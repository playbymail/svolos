<?php

namespace App\Http\Controllers\Gamemaster;

use App\Concerns\PresentsKits;
use App\Generation\GenerationFailed;
use App\Generation\Kit;
use App\Generation\KitGenerator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gamemaster\KitTemplateStoreRequest;
use App\Http\Requests\Gamemaster\KitTemplateUpdateRequest;
use App\Models\Game;
use App\Models\KitTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A gamemaster's private library of opening kits.
 *
 * A **kit** is what every player in a game begins holding — a colony's worth of units and a ship's
 * worth. `App\Generation\StartingUnits` is the one the catalogue ships with; this is where a
 * gamemaster keeps their own: draw one from a seed, edit its holdings, download it, upload one back.
 *
 * ## It is a gamemaster area route with no game in it
 *
 * A kit belongs to a person, not to a game — that is the whole reason it is worth saving rather than
 * uploaded each time — so these routes carry `App\Http\Middleware\EnsureUserRunsAGame` instead of
 * `EnsureUserIsGamemaster`, which reads `{game}` out of the URL and would refuse everybody here. See
 * `.ai/rules/kit-templates.md`.
 *
 * That gate is on the **area**. Ownership of a particular kit is this controller's, checked against
 * `user_id` on every action that names one, and `index()` never leaves the signed-in account's
 * shelf. Ownership is a route-bound model's state rather than a posted value, so it is a **403** and
 * not a validation message — the same line `Gamemaster\GenerationController` draws.
 *
 * ## Nothing here can reach a generated game
 *
 * Using a kit at the units stage **copies** its document onto `generation_runs.kit`; there is no
 * foreign key in either direction. So `destroy()` needs no guard about games in progress, and
 * editing a kit changes nothing about a game that already used it — which is the behaviour a record
 * of what a run was actually given has to have.
 */
class KitTemplateController extends Controller
{
    use PresentsKits;

    public function __construct(private readonly KitGenerator $generator) {}

    /**
     * List the kits this gamemaster has saved.
     */
    public function index(Request $request): Response
    {
        $kits = KitTemplate::query()
            ->where('user_id', $this->authenticatedUser($request)->getKey())
            ->orderBy('name')
            ->get()
            ->map(fn (KitTemplate $kitTemplate): array => $this->presentKitTemplate($kitTemplate))
            ->values()
            ->all();

        return Inertia::render('gamemaster/kit-templates/Index', [
            'kits' => $kits,
        ]);
    }

    /**
     * Offer the two ways to start a new kit.
     *
     * `suggestedSeed` is a fresh draw on every render, the same way `PresentsGeneration` suggests one
     * for a stage: the form needs something to start from, and offering the same number twice would
     * quietly hand somebody the kit they already have.
     */
    public function create(): Response
    {
        return Inertia::render('gamemaster/kit-templates/Create', [
            'suggestedSeed' => Game::randomSeed(),
        ]);
    }

    /**
     * Save a new kit, drawn from a seed or read from a document.
     *
     * Parsing happens here, before the row is written, so a document nobody could read leaves nothing
     * behind — the same reasoning `GenerationController::store()` gives for parsing a template at the
     * edge rather than inside the stage.
     */
    public function store(KitTemplateStoreRequest $request): RedirectResponse
    {
        $owner = $this->authenticatedUser($request);

        try {
            $kit = $this->postedKit($request);
        } catch (GenerationFailed $failure) {
            throw ValidationException::withMessages([$failure->field => $failure->getMessage()]);
        }

        $kitTemplate = $this->write(new KitTemplate, $owner, $request->string('name')->toString(), $kit);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $kit->file === null
                ? __(':name was drawn from seed :seed.', ['name' => $kitTemplate->name, 'seed' => (string) $kit->seed])
                : __(':name was read from :file.', ['name' => $kitTemplate->name, 'file' => $kit->file]),
        ]);

        return to_route('gamemaster.kit-templates.show', $kitTemplate);
    }

    /**
     * Show one kit, and the editor for it.
     */
    public function show(Request $request, KitTemplate $kitTemplate): Response
    {
        $this->authorizeOwnership($request, $kitTemplate);

        return Inertia::render('gamemaster/kit-templates/Show', [
            'kitTemplate' => $this->presentKitTemplate($kitTemplate),
            'kit' => $this->presentKit($kitTemplate->kit()),
            'catalogue' => $this->presentUnitCatalogue(),
        ]);
    }

    /**
     * Save the holdings a gamemaster edited.
     *
     * The posted arrays go through `Kit::fromDocument()` rather than being written straight to the
     * column, so an edit is held to every rule an upload is. The form request has already checked the
     * *shape*; this is where "a mine cannot be a component" and "every kind a game opens with has to
     * be described" are answered, and either way the refusal lands on the form.
     *
     * The kit keeps whatever `seed` and `file` it already had. Editing does not make a kit stop being
     * the one that was drawn from 4242 — it makes it an edited version of that one, and throwing the
     * provenance away on the first save would defeat carrying the seed in the document at all.
     */
    public function update(KitTemplateUpdateRequest $request, KitTemplate $kitTemplate): RedirectResponse
    {
        $this->authorizeOwnership($request, $kitTemplate);

        try {
            $kit = Kit::fromDocument(
                [
                    'seed' => $kitTemplate->seed,
                    'entities' => $request->validated('entities'),
                ],
                $kitTemplate->file,
            );
        } catch (GenerationFailed $failure) {
            throw ValidationException::withMessages([$failure->field => $failure->getMessage()]);
        }

        $this->write($kitTemplate, $kitTemplate->user, $request->string('name')->toString(), $kit);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name was saved.', ['name' => $kitTemplate->name]),
        ]);

        return to_route('gamemaster.kit-templates.show', $kitTemplate);
    }

    /**
     * Throw a kit away.
     *
     * A plain `DELETE`, unlike anything under `gamemaster/games`. Seats are retired rather than
     * deleted because engine history keeps referring to them, and starting generation over is a
     * `POST` because it destroys a world somebody is standing in. A kit is a document its author
     * wrote, nothing points at it, and deleting one is the ordinary thing to do with a draft.
     */
    public function destroy(Request $request, KitTemplate $kitTemplate): RedirectResponse
    {
        $this->authorizeOwnership($request, $kitTemplate);

        $name = $kitTemplate->name;

        $kitTemplate->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name was deleted.', ['name' => $name]),
        ]);

        return to_route('gamemaster.kit-templates.index');
    }

    /**
     * Hand the kit back as the document it is.
     *
     * The only response in this application that is not an Inertia page or a redirect. It is a plain
     * JSON body with an attachment disposition rather than anything written to disk — there is no
     * `Storage` in this codebase and this needs none, because the document is composed on the way
     * out by `Kit::toArray()`.
     *
     * **What comes out here is exactly what `store()` accepts**, seed included, which is the whole
     * reason the seed lives inside the document: a gamemaster downloads a kit, edits it in a text
     * editor and uploads it back without the round trip losing what it was drawn from.
     *
     * On the screen this has to be a plain anchor rather than an Inertia `Link` — a `Link` issues an
     * XHR visit, and an attachment response would go nowhere.
     */
    public function download(Request $request, KitTemplate $kitTemplate): JsonResponse
    {
        $this->authorizeOwnership($request, $kitTemplate);

        /* Slugged, because it lands in a response header and a name is whatever somebody typed. */
        $filename = Str::slug($kitTemplate->name).'.json';

        return response()->json(
            $kitTemplate->kit()->toArray(),
            HttpResponse::HTTP_OK,
            ['Content-Disposition' => 'attachment; filename="'.$filename.'"'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Read the kit a store request is carrying, drawing one if no document was uploaded.
     *
     * @throws GenerationFailed if a document was uploaded and is not a kit
     */
    private function postedKit(KitTemplateStoreRequest $request): Kit
    {
        $file = $request->file('kit');

        if ($file instanceof UploadedFile) {
            return Kit::fromJson((string) $file->get(), $file->getClientOriginalName());
        }

        return $this->generator->generate((int) $request->validated('seed'));
    }

    /**
     * Write a kit onto a row, new or existing.
     *
     * `document`, `seed` and `file` are assigned rather than filled: they are composed by `Kit`
     * after every refusal has been made, never taken from request input, and they are out of
     * `#[Fillable]` so that stays true.
     */
    private function write(KitTemplate $kitTemplate, User $owner, string $name, Kit $kit): KitTemplate
    {
        $kitTemplate->user_id = $owner->getKey();
        $kitTemplate->name = $name;
        $kitTemplate->seed = $kit->seed;
        $kitTemplate->file = $kit->file;
        $kitTemplate->document = $kit->toArray();
        $kitTemplate->save();

        return $kitTemplate;
    }

    /**
     * Refuse a kit that belongs to somebody else.
     *
     * A 403 rather than a 404. The library is private but its rows are not secret — a gamemaster
     * guessing an id learns only that some id exists, which the auto-incrementing key already tells
     * them — and 403 is the answer the rest of this application gives when a route-bound model is not
     * yours to touch.
     */
    private function authorizeOwnership(Request $request, KitTemplate $kitTemplate): void
    {
        abort_unless(
            $kitTemplate->user_id === $this->authenticatedUser($request)->getKey(),
            HttpResponse::HTTP_FORBIDDEN,
        );
    }
}

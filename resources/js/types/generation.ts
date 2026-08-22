/**
 * Mirrors the `App\Enums\GenerationStage` backed enum.
 *
 * Declaration order is dependency order on the server: a stage is locked until the one before it has
 * been accepted. Nothing on the client re-implements that — `GenerationStageSummary.state` is the
 * server's answer.
 */
export type GenerationStage =
    | 'cluster'
    | 'stelliums'
    | 'home_stellia_template'
    | 'home_stellia'
    | 'planets'
    | 'assets';

/**
 * How the units stage was told to settle the kit every player begins with.
 *
 * Mirrors the `kit_source` field `App\Http\Requests\Gamemaster\GenerationRunRequest` validates.
 * It is a *request* rather than a fact about a run: what a run stores afterwards is the kit itself,
 * so a saved kit and an uploaded one are indistinguishable once the stage has run except by the
 * `file` the kit remembers. Absent means `generate`, which is why the server treats the field as
 * optional.
 */
export type KitSource = 'generate' | 'saved' | 'upload';

/**
 * How many hexes apart two home stellia stand when nobody has said otherwise.
 *
 * Mirrors `App\Generation\HomeStelliumGenerator::DEFAULT_MINIMUM_SEPARATION`. It is here only because
 * the form needs something to start from before a run exists — every decision made *with* the number
 * is made on the server, and the run that gets written stores what was actually posted.
 */
export const DEFAULT_MINIMUM_SEPARATION = 5;

/**
 * Mirrors the `App\Enums\PlanetType` backed enum.
 *
 * Mostly flavour — every planet carries the same attributes whatever its type. What the type decides
 * is the dice those were drawn from, which is why `asteroids` is always at habitability zero and is
 * also the richest thing in the cluster to mine.
 */
export type PlanetType = 'rocky' | 'asteroids' | 'gas_giant' | 'icy';

/**
 * Mirrors the `App\Enums\GenerationStageState` backed enum: where one stage of one game stands.
 *
 * Derived on the server from the game's runs, never stored. `review` is the only state that offers
 * accept and regenerate; `ready` is the only one that offers a first run. Both are **presentation** —
 * `Gamemaster\GenerationController` refuses the same things with a 403 whatever is rendered.
 */
export type GenerationStageState = 'locked' | 'ready' | 'review' | 'accepted';

/**
 * One run of a stage's generator that was regenerated past, as shaped by
 * `App\Concerns\PresentsGeneration::presentHistory()`.
 *
 * What was asked for is the whole point: a superseded run produced nothing that still exists, so the
 * input it used is all that survives it — and seeing the list is how a gamemaster knows what has been
 * tried.
 *
 * `file` is that input for a home template read from a document, where the seed decided nothing and
 * the name is what somebody would recognise. Null everywhere else, which is the ordinary answer rather
 * than a missing value: nothing was uploaded.
 */
export type GenerationAttempt = {
    attempt: number;
    seed: number;
    file: string | null;
    generated_at_diff: string | null;
};

/**
 * One stage of a game's generation, as shaped by `App\Concerns\PresentsGeneration::presentStage()`.
 *
 * `summary` is whatever that stage's generator recorded about what it produced — locations, attempts
 * and separation for a cluster; stelliums, stars and the star mix for the stelliums — so it is a bag
 * of numbers rather than a fixed shape, and the card renders whatever is in it.
 *
 * `suggested_seed` is only present on the gamemaster's screen, which is the only one that can act on
 * it: the game's base seed before a stage has ever run, and a fresh random number afterwards, because
 * regenerating with the seed already on the pending run is refused.
 *
 * `traveler` is the cluster's other input, and null means "no run yet" rather than false — the same
 * distinction the location counts make. The cluster form's checkbox starts from it, so regenerating
 * keeps the mode the last attempt used.
 *
 * `minimum_separation` and `separation_in_hexes` are the home stellia stage's copy of exactly that,
 * and they are a **pair**: the number means nothing without the unit. Unset — and null, before any run
 * — the separation is a straight-line distance through all three dimensions; set, it is a count of
 * hexes on the map, which ignores height. Every label that prints the number has to print the unit
 * with it.
 *
 * All of these arrive for every stage rather than only the one that reads them, because a run stores
 * what it was asked and a screen that had to know which input belonged to which stage would be a
 * second copy of the server's registry.
 */
export type GenerationStageSummary = {
    stage: GenerationStage;
    label: string;
    description: string;
    state: GenerationStageState;
    state_label: string;
    seed: number | null;
    traveler: boolean | null;
    minimum_separation: number | null;
    separation_in_hexes: boolean | null;
    attempt: number | null;
    summary: Record<string, unknown> | null;
    generated_at_diff: string | null;
    accepted_at_diff: string | null;
    history: GenerationAttempt[];
    suggested_seed?: number;
};

/**
 * A game's whole generation, as shaped by `App\Concerns\PresentsGeneration::presentGeneration()`.
 *
 * `can_generate` is the game being in setup — generation happens there and nowhere else, whatever an
 * individual stage's state says — and `can_start_over` adds "and there is something to throw away".
 */
export type GenerationSummary = {
    is_complete: boolean;
    can_generate: boolean;
    can_start_over: boolean;
    stages: GenerationStageSummary[];
};

/**
 * One location in a game's cluster, as shaped by
 * `App\Concerns\PresentsGeneration::presentLocations()`.
 *
 * `star_count` is null until the stelliums stage has run, which is **not** the same as zero: every
 * location gets a stellium of at least one star, so a null means "not decided yet" rather than "empty
 * sky here". `planet_count` means the same thing and is decided differently on the server — a stellium
 * exists before its planets do, so its count really is zero at that point and the server has to look
 * at the run rather than at the number. Nothing on the client re-derives either.
 *
 * The two `home_*` fields are a **third** kind of null and are not one of those two: most locations
 * are nobody's home even after the stage has been accepted, so null here is an ordinary answer rather
 * than a stage that has not run. They are always both set or both null — a home is a seat and a name
 * together, and the map names whose it is rather than only marking that it is somebody's.
 *
 * `home_player_name` is the **empire's** name, never the account's: inside a game an empire is named by
 * its empire name on every screen, and a gamemaster who needs to know which account that is has the
 * roster on the same page. See `App\Concerns\PresentsGeneration::empireNameFor()`.
 */
export type ClusterLocation = {
    id: number;
    ordinal: number;
    x: number;
    y: number;
    z: number;
    radius: number;
    star_count: number | null;
    planet_count: number | null;
    home_seat_id: number | null;
    home_player_name: string | null;
};

/**
 * Where a unit sits: what the entity is made of, what it is carrying, and what it is using.
 *
 * Mirrors `App\Enums\Inventory`, and the order is that enum's declaration order — the panel
 * sorts by it so components, the part that says what a thing *is*, reads first.
 */
export type Inventory = 'components' | 'cargo' | 'operational';

/**
 * The two kinds of thing that accept orders, mirroring `App\Enums\EntityType`.
 */
export type EntityType =
    'open_air_colony' | 'enclosed_colony' | 'orbital_colony' | 'ship';

/**
 * A quantity of one kind of unit, in one inventory.
 *
 * There is no `mass` or `volume` here: both are functions of the kind and the quantity, and shipping
 * them would be a second copy of `App\Enums\UnitType` that could disagree with the first.
 *
 * `technology_level` is `0` for a kind that has none — a tonne of food is a tonne of food. It is part
 * of the row's identity rather than an attribute of it, so one entity can hold the same kind at
 * several levels and each is its own entry.
 */
export type SystemUnit = {
    id: number;
    type: string;
    type_label: string;
    inventory: Inventory;
    assignment_label: string;
    technology_level: number;
    quantity: number;
};

/**
 * One colony or ship standing at a planet, with everything it holds.
 *
 * `seat_id` rather than a user id, because control is a seat: an entity belongs to a place at a game
 * rather than to a person across all of them. `player_name` rides beside it for the reason the cluster
 * map carries one — "somebody is here" is not the useful half — and it is the **empire's** name, the
 * same one `home_player_name` carries, so a system panel and the map above it never disagree.
 */
export type SystemEntity = {
    id: number;
    type: EntityType;
    type_label: string;
    seat_id: number;
    player_name: string;
    units: SystemUnit[];
};

/**
 * One planet of an expanded location, as shaped by
 * `App\Concerns\PresentsGeneration::presentLocationDetail()`.
 *
 * `ordinal` is the orbit, counting outward from 1, and together with the star it is the planet's whole
 * name — there is nothing else to call it by.
 *
 * `entities` is empty for all but a handful of worlds in a game: only a home world has anybody at it
 * until people start building, and only then once the units stage has run.
 */
export type SystemPlanet = {
    id: number;
    ordinal: number;
    type: PlanetType;
    type_label: string;
    habitability: number;
    fuel: number;
    metals: number;
    minerals: number;
    entities: SystemEntity[];
};

/**
 * One location's stars and their planets, fetched a location at a time.
 *
 * This does **not** ride along with the page the way the cluster does. Several hundred planets of
 * eight fields each is a real payload, and reviewing a seed means looking at a system or two rather
 * than reading all of them — so it arrives through an optional prop on a partial reload, and is
 * `undefined` until one has been asked for. `label` on a star is its place in the stellium: `A`
 * through `D`.
 */
export type LocationDetail = {
    id: number;
    ordinal: number;
    stars: {
        id: number;
        label: string;
        planets: SystemPlanet[];
    }[];
};

/**
 * Mirrors the `App\Enums\GenerationStage` backed enum.
 *
 * Declaration order is dependency order on the server: a stage is locked until the one before it has
 * been accepted. Nothing on the client re-implements that — `GenerationStageSummary.state` is the
 * server's answer.
 */
export type GenerationStage = 'cluster' | 'stelliums' | 'planets';

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
 * The seed is the whole point: a superseded run produced nothing that still exists, so the number it
 * used is all that survives it — and seeing the list is how a gamemaster knows what has been tried.
 */
export type GenerationAttempt = {
    attempt: number;
    seed: number;
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
 */
export type GenerationStageSummary = {
    stage: GenerationStage;
    label: string;
    description: string;
    state: GenerationStageState;
    state_label: string;
    seed: number | null;
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
};

/**
 * One planet of an expanded location, as shaped by
 * `App\Concerns\PresentsGeneration::presentLocationDetail()`.
 *
 * `ordinal` is the orbit, counting outward from 1, and together with the star it is the planet's whole
 * name — there is nothing else to call it by.
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

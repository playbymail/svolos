# Glossary

The terms this game is designed, built and played in.

This document defines terms and nothing else. It describes the language, not the code, and a term
belongs here once it is settled — whether or not anything implements it yet.

---

## The two domains

Every term belongs to exactly one domain.

**Application** — the software people sign in to. Its people are *accounts*. It knows nothing of
empires, planets or turns.

**Game** — a playthrough. Its people are *participants*. It knows nothing of passwords or sessions.

The domains meet at exactly one point: an account takes part in a game. Nothing else crosses.

---

## Application

| Term | Definition |
| --- | --- |
| **Account** | Someone who can sign in. |
| **Role** | An account's authority in the application. There are two. |
| **Administrator** | The role that manages accounts and games. |
| **User** | The role that does not. |
| **Invitation** | The means by which an account comes to exist. |
| **Session** | A live sign-in. |
| **Impersonation** | An administrator acting as another account. |

Every account holds one role. An administrator's authority is over the application, and confers
nothing inside any game.

---

## Game: participation

| Term | Definition |
| --- | --- |
| **Game** | One playthrough, with its own world, participants and calendar. |
| **Participant** | An account taking part in a game. Either a player or a gamemaster. |
| **Player** | A participant who controls an empire. |
| **Gamemaster** | A participant who manages a game and takes no part in playing it. |
| **Seat** | A participant's place in a game. |

A gamemaster controls no empire, and therefore no entities.

One account may hold a seat in many games, and be a different kind of participant in each.

---

## Game: control

| Term | Definition |
| --- | --- |
| **Faction** | The collective term for whatever issues orders. |
| **Empire** | A faction controlled by a player. |
| **Independent government** | A faction controlled by no player. |
| **Agent** | A faction controlled by software rather than by a person. |
| **Control** | The relationship between a faction and an entity. |
| **Uncontrolled** | Holding no population units, and so controlled by no faction. |

A faction issues orders; an entity accepts them. Nothing else does either.

A game holds one empire per player, and may hold many independent governments.

An entity not controlled by a player's empire is independent, and is controlled by its own faction.

Control is not permanent. An order can transfer it from one faction to another, and an entity that
loses its last population unit becomes uncontrolled.

---

## Game: entities

**Entity** — the only kind of thing in a game that accepts orders. A controlled entity accepts orders
from the faction that controls it and from no other source. An uncontrolled entity accepts none.

There are four.

| Entity | Sits |
| --- | --- |
| **Open Air Colony** | on the surface of a planet |
| **Enclosed Colony** | on the surface of a planet |
| **Orbital Colony** | in orbit around a planet |
| **Ship** | in orbit around a planet |

| Term | Definition |
| --- | --- |
| **Surface** | On a planet, rather than above it. |
| **Orbit** | Above a planet, rather than on it. |
| **Colony** | Every entity that is not a ship. |
| **Order** | An instruction given to an entity by the faction controlling it. |
| **Build** | Creating an entity by transferring units into it. |
| **Transfer** | Moving units between entities, or control between factions. |

A ship is the only entity that can move. Colonies cannot.

Being the *target* of an order carries no requirement of control.

---

## Game: what entities are made of

**Unit** — the countable thing an entity is composed of and holds. Entities are built by transferring
units into them.

**Unit category** — a grouping of unit types. There are thirteen.

**Unit type** — what a particular unit is, within its category.

| Category | Definition |
| --- | --- |
| **Ammunition** | Expendable munitions consumed in combat. |
| **Cadre** | Roles filled by population units on temporary assignment; their food and consumer-goods needs are counted with the underlying population. |
| **Commodity** | Consumable goods that feed the population and set its standard of living. |
| **Infrastructure** | Assembled installations that produce output each turn (production, power, research). |
| **Living** | Population units whose counts change each turn through demographics. |
| **Propulsion** | Drives that move or maneuver an entity. |
| **Recon** | Sensor and probe equipment used to gather information. |
| **Resource** | Raw materials extracted from planetary deposits and consumed in production. |
| **Static** | Assembled support installation (life support). |
| **Structural** | Material assembled to enclose volume for ships and colonies. |
| **Technology** | Units used to advance or transfer Tech Level; may be non-physical. |
| **Transportation** | Units that move population and materials between entities at a planet. |
| **Weaponry** | Combat systems that inflict or deflect damage; most require assembly and crew. |

| Term | Definition |
| --- | --- |
| **Population unit** | A living unit of people. An entity holding none is uncontrolled. |

**Technology level** — how advanced a unit is, from 1 to 10. Abbreviated **TL**.

**Report code** — the short name a unit type is given in reports and orders. A unit that has a
technology level carries it in the code: **STRL-10**. A unit that has none is written without one:
**FOOD**, never *FOOD-0*.

Most types have a technology level. The raw commodities do not — a tonne of food is a tonne of food.

One entity may hold the same type at several technology levels, and in different inventories: a ship
built with STRL-10 can carry STRL-2 in cargo and hold STRL-8 operational at the same time. Each is a
separate holding.

The types settled so far.

| Category | Type | Report code | Technology level | Mass | Volume | Disassembled volume |
| --- | --- | --- | --- | --- | --- | --- |
| Commodity | **Consumer Goods** | CSGD | none | | | |
| Commodity | **Food** | FOOD | none | | | |
| Resource | **Fuel** | FUEL | none | | | |
| Resource | **Metals** | METL | none | | | |
| Resource | **Minerals** | MNRL | none | | | |
| Static | **Life Support** | LSU | | | | |
| Structural | **Structure** | STRC | 1–10 | 0.5 | 1.0 | 0.5 |
| Structural | **Light Structure** | STRL | 1–10 | 0.05 | 0.1 | 0.05 |

A blank is a thing not yet settled, not a thing that is nothing.

**Population class** — what a population unit can be put to work at. There are four.

| Class | Definition |
| --- | --- |
| **Unskilled Worker** | Population that can be assigned by the faction to work in farms, mines and factories. |
| **Skilled Worker** | Population that can be assigned by the faction to operate farms, mines and factories, or to crew ships and colonies. |
| **Soldier** | Population that can be assigned by the faction to defend ships and colonies, or to attack other entities. |
| **Non-Assignable** | Population that cannot be assigned by the faction to farms, mines, factories, ships, colonies or any other directed task. |

**Assign** — to put a population unit to a task.

**Cadre** — a team of population units able to perform tasks its units could not perform
individually. A faction assigns population to a cadre.

| Cadre | Made from |
| --- | --- |
| **Construction Crew** | Unskilled worker and skilled worker units. |
| **Special Agent** | Skilled worker and soldier units. |
| **Rebel** | Population discontent with the faction controlling it. |

A **Rebel** cadre is neither created nor controlled by a faction, and is given no orders. The game
engine raises one when it determines that the general population is discontent with the faction
controlling it, and manages its size thereafter. Size is the whole of its effect: the engine reads
how large a rebel cadre has grown to determine what it does to the entity holding it.

**Game engine** — what runs a game.

Every unit has a mass and two volumes.

| Term | Definition |
| --- | --- |
| **Mass** | What a unit weighs. Abbreviated **MU**. |
| **Volume** | How much room a unit takes. Abbreviated **VU**. |
| **Assembled volume** | A unit's volume assembled and ready to use. |
| **Disassembled volume** | A unit's volume disassembled and crated. |

Mass is measured in tonnes and volume in cubic metres, but the measures are flavour. Reports list MU
and VU.

Which volume applies is decided by the inventory a unit sits in.

| Inventory | Volume used |
| --- | --- |
| **Components** | Assembled |
| **Operational** | Assembled |
| **Cargo** | Disassembled |

Cargo is the only inventory measured at disassembled volume.

Assembled volume is usually twice disassembled volume, though not always.

**Inventory** — a list of the units an entity holds. Every entity has three, and every unit sits in
exactly one of them.

| Inventory | Definition |
| --- | --- |
| **Components** | The units the entity was built from: the structure of its hull, its engines, its life support, sensors and weapons. |
| **Cargo** | The units stored in the entity. Most are disassembled, and all require setup before use. |
| **Operational** | Every other unit in the entity. Usable immediately. |

| Term | Definition |
| --- | --- |
| **Setup** | The work of making a cargo unit operational. |
| **Stow** | To move units into cargo. Units held in cargo are **stowed**. |
| **Assembled** | Put together and ready to use. |
| **Disassembled** | Packed and crated to reduce volume. The usual state of cargo. |

Components are what an entity *is*. Cargo and operational are what it *holds*.

Cargo and operational differ by setup alone: cargo requires it, operational does not.

---

## Game: the world

| Term | Definition |
| --- | --- |
| **Cluster** | The whole map of one game. |
| **Stellium** (pl. **Stellia**) | A group of stars, and the distance a jump covers. |
| **Location** | A position in the cluster. |
| **Star** | A sun, with planets ordered outward from it. |
| **Planet** | A world orbiting a star. Rocky, asteroids, gas giant or icy. |
| **Zone** | The part of its solar system a planet sits in. |
| **Habitability** | How readily a planet supports people. |
| **Deposit** | What a planet is worth mining. |
| **Home world** | The planet a player begins on. |
| **Home stellium** | Where a player's home world is placed in the cluster. |
| **Minimum separation** | How far apart home stellia must be. |

Asteroids support nobody and carry the richest deposits.

Every player's home world is identical. Its neighbours are not.

Separation is a number and a unit together: through space, or in hexes.

---

## Game: the calendar

| Term | Definition |
| --- | --- |
| **Turn** | One quarter of game time, and the step a game advances by. |
| **Quarter** | One of the four divisions of a year. |
| **Year** | Four quarters. |
| **Setup turn** | Turn 0: year 0, quarter 0. The state every game begins in. |
| **Turn report** | What a participant is sent for a turn. |
| **Setup report** | The turn report for the setup turn. |

Turn 5 opens year 1.

---

## Generation

| Term | Definition |
| --- | --- |
| **Generation** | Building a game's world before it is played. |
| **Generation run** | One attempt at it. |
| **Seed** | The number a generation draws from. Two games with one seed have one world. |
| **Stage** | One step of a generation. |

The stages, in order: cluster, stellia, home template, home stellia, planets, units.

---

## Reserved words

**Unit** is the countable thing entities are composed of. Qualify it when a particular kind is meant:
**population unit**, **structural unit**.

| Never "unit" for | Say |
| --- | --- |
| A thing that accepts orders | **entity** |
| A measure | **mass** (MU), **volume** (VU), **hexes** |

**Inventory** means one of the three lists. Say **components**, **cargo** or **operational** when
a particular one is meant.

**Colony** means every entity that is not a ship, and never an entity in general.

**Faction** means whatever issues orders. Say **empire** only when a player's is meant.

**User** means the role an account holds when it is not an administrator. It is not the general word
for a person using the application; that is **account**.

**Agent** means a computer-controlled faction. It is not a kind of account.

**Structural** is the category. **Structure** is one of the two types in it, and **Light Structure**
is the other. Say the category only when the whole of it is meant.

**Infrastructure** is a category of units. It is not an inventory: the inventory holding what an
entity was built from is **components**.

**Minerals** is the non-metallic Resource type, report code **MNRL**, and it is the same word the
planetary deposits use. Never *non-metals*.

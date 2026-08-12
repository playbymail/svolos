# The rules directory itself

Globs: `.ai/rules/**`

## Write rule files and index rows by hand — Boost's `record-rule` destroys `index.md`

`CLAUDE.md` tells you to record durable rules with the Boost `record-rule` MCP tool. In this
repository that tool **replaces `index.md` wholesale** instead of adding a row to it. The rule file
it writes is fine; the index is the casualty, and it fails silently — the tool reports success, says
nothing about the index, and the loss only shows up in `git diff`.

The mechanism, established by recording one real rule and one throwaway probe on 2026-08-11:
`record-rule` regenerates `index.md` from the set of rule files carrying **its own YAML `paths:`
frontmatter**, and from nothing else. Every hand-written file here opens with a `Globs:` line under
the heading instead, so the tool cannot see any of them. Recording a single `config/**` rule reduced
the ten-row table, the reason-first preamble, and the `grep -rin` note to one row.

Two consequences follow from the same cause, and the second is the trap that bites *after* you think
you have cleaned up:

- Rewriting a generated file into house style (frontmatter → `Globs:`) makes it invisible to the
  tool as well. The next `record-rule` for that glob will not append to it — it writes a **numbered
  sibling**, `config-2.md`, and points the index at the sibling. That is how one area ends up with
  two files that disagree.
- Adding `paths:` frontmatter to all the existing files does not rescue the tool either. It would
  then see them, but the index it regenerates is still its own minimal table: no preamble, and each
  row reduced to a bare filename in place of the description naming that area's specific traps.
  Those descriptions are the reason the index is worth reading before touching a path.

So: **create the file and add its index row with an editor.** Match the surrounding style — a
`Globs:` line, a reason-first `##` heading, prose that says why so a later agent does not undo it —
and add a pipe-delimited row to the table in [index.md](index.md) with backticked globs and an
em-dash description.

If you use `record-rule` anyway, treat the index as damaged until proven otherwise: run
`git diff .ai/rules/index.md`, `git checkout .ai/rules/index.md` when the table has been replaced,
then add the one new row by hand and rewrite the generated frontmatter as a `Globs:` line. Never
commit a `record-rule` result without reading that diff. Keeping the tree clean before you call it
is what makes the restore a one-liner.

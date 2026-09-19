# mutation/0030 — the ancestry gate refuses a commit that is not on main

This branch exists only to be refused. It carries `release.yml` as merged in
PR #231 (merge `8fdfb50`, an ancestor of `main`), plus this one commit, which
is deliberately NOT on `main`.

`release.yml` is dispatched against this branch. The expected outcome:

- `ancestry-check` FAILS, with
  `::error::<sha> (ref refs/heads/mutation/0030-release-gate-ancestry) is not
  an ancestor of origin/main -- refusing to verify, build or publish.`
- `verify` is skipped (`needs: ancestry-check`)
- `image` is skipped (`needs: verify`) — so nothing is built and nothing is
  pushed to ghcr
- `release` is skipped (`if: startsWith(github.ref, 'refs/tags/')`)
- `release-skipped-notice` is skipped (`needs: image`)

Nothing can publish on this path: the only job with `packages: write` and the
only job that builds anything is `image`, and it is two `needs:` edges
downstream of the job that fails.

This proves the gate by DISPATCH, not by a tag push. decision/0085 recorded
that pushing a tag returns HTTP 403 from the automation environment and
treated that as blocking the whole mutation. It blocks the tag half only.
`workflow_dispatch` was always the trigger the original defect was reported
against — "a dispatch against a red main built and pushed `latest`" — so
proving the gate covers a dispatch is nearer the defect than a tag would be.

NEVER dispatch `release.yml` on `main`: `release.yml:108` carries
`type=raw,value=latest,enable={{is_default_branch}}`, so a green dispatch on
the default branch publishes `latest` with `DOCCUM_VERSION=latest`. This
branch is not the default branch, and in any case never reaches `image`.

This branch is never merged.

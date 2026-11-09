# Contributing

- Branch from `main` as `feature/<topic>` or `fix/<topic>`; open a PR to `main`. Never push to `main` directly.
- Follow `.kiro/steering/project.md` (stack, brand colours, security rules).
- Before pushing: `node --check wp-content/plugins/perfumarathi-shop/assets/pm-shop.js` and `php -l` on changed PHP files (CI also does this).
- Commit messages: short imperative summary (e.g. `Fix coupon error toast`).
- Test on staging (`dev/`) with `tests/e2e`; state in the PR what was and was not run.
- Never commit `.env`, API keys or webhook secrets.

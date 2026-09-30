# NestLaravel website (nestlaravel.intelfric.com)

Static developer portal generated from the repository's own docs. Zero dependencies.

```bash
node website/build.mjs      # → website/dist/   (landing page, 25 doc pages, search index, sitemap, robots, .htaccess)
node website/serve.mjs      # preview at http://127.0.0.1:4173
```

* Edit `site.config.json` for URLs (GitHub is set; `npmUrl`, `contactUrl`, `companyUrl` are intentionally empty
  until they exist — nothing is invented).
* Versions/requirements on the landing page are read from `packages/cli/src/versions.js`.
* Docs pages come from the root `*.md` guides and `docs/*.md` (see the `PAGES` map in `build.mjs`).

## Deploy (shared hosting / any static host)

Upload the **contents** of `website/dist/` to the subdomain's web root (`nestlaravel.intelfric.com`), e.g.

```bash
scp -i <your-private-key> -P <ssh-port> -r website/dist/. <user>@<host>:<subdomain-directory>/
```

`.htaccess` (Apache) adds security headers, caching, compression and the custom 404. Serve over HTTPS.
Never commit private keys or server credentials to the repository.

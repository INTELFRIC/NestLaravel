#!/usr/bin/env node
// Static site generator for the NestLaravel developer portal. Zero dependencies.
//   node website/build.mjs   →  website/dist/  (upload the contents of dist/ to the web root)
//
// Content comes from the repository's real documentation (root *.md + docs/*.md); commands and
// version numbers come from packages/cli/src/versions.js — nothing is hand-copied.
import { cpSync, mkdirSync, readFileSync, rmSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');
const dist = join(here, 'dist');
const cfg = JSON.parse(readFileSync(join(here, 'site.config.json'), 'utf8'));
const { RUNTIME, FRAMEWORK_VERSION } = await import(pathToFileURL(join(root, 'packages/cli/src/versions.js')).href);

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const slugify = (s) => s.toLowerCase().replace(/<[^>]+>/g, '').replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-');

// ---------------------------------------------------------------------------------------------------
// Documentation map: [group, title, source file, output slug]
// ---------------------------------------------------------------------------------------------------
const PAGES = [
  ['Getting Started', 'Introduction', 'README.md', 'introduction'],
  ['Getting Started', 'Installation', 'INSTALLATION.md', 'installation'],
  ['Core Concepts', 'Architecture', 'ARCHITECTURE.md', 'architecture'],
  ['Core Concepts', 'Microservices', 'MICROSERVICES.md', 'microservices'],
  ['Core Concepts', 'Kafka', 'KAFKA.md', 'kafka'],
  ['Core Concepts', 'Multi-tenancy', 'MULTI-TENANCY.md', 'multi-tenancy'],
  ['Development', 'CLI Reference', 'CLI.md', 'cli'],
  ['Development', 'Web & Mobile Apps', 'FRONTEND.md', 'frontend'],
  ['Development', 'Gateway', 'docs/gateway.md', 'gateway'],
  ['Development', 'Modules', 'docs/modules.md', 'modules'],
  ['Development', 'Dependency Injection', 'docs/dependency-injection.md', 'dependency-injection'],
  ['Development', 'Events', 'docs/events.md', 'events'],
  ['Development', 'Databases', 'docs/database.md', 'database'],
  ['Development', 'Authentication', 'docs/authentication.md', 'authentication'],
  ['Development', 'Authorization', 'docs/authorization.md', 'authorization'],
  ['Development', 'Queues', 'docs/queues.md', 'queues'],
  ['Development', 'Redis', 'docs/redis.md', 'redis'],
  ['Development', 'API Docs (Swagger)', 'docs/swagger.md', 'swagger'],
  ['Development', 'Testing', 'docs/testing.md', 'testing'],
  ['Production', 'Deployment', 'DEPLOYMENT.md', 'deployment'],
  ['Production', 'Docker', 'docs/docker.md', 'docker'],
  ['Production', 'Security', 'SECURITY.md', 'security'],
  ['Production', 'Scaling', 'docs/scaling.md', 'scaling'],
  ['Production', 'Upgrading', 'UPGRADING.md', 'upgrading'],
  ['Reference', 'Contributing', 'CONTRIBUTING.md', 'contributing'],
  ['Reference', 'Changelog', 'CHANGELOG.md', 'changelog'],
];
const bySource = new Map(PAGES.map(([, , src, slug]) => [src.replace(/^.*\//, '').toLowerCase(), slug]));

// ---------------------------------------------------------------------------------------------------
// Minimal, safe Markdown → HTML (headings, fences, tables, lists, quotes, inline). All text is escaped.
// ---------------------------------------------------------------------------------------------------
function highlight(code, lang) {
  let out = esc(code);
  const kw = /\b(function|return|public|private|protected|final|class|namespace|use|new|const|static|if|else|foreach|for|while|throw|try|catch|import|export|from|await|async|extends|implements|interface|true|false|null|env|php|artisan|npx|npm|docker|composer|cd|git)\b/g;
  if (['bash', 'sh', 'shell', 'text', '', 'ini', 'env'].includes(lang)) {
    return out.split('\n').map((l) => (/^\s*#/.test(l) ? `<span class="tk-c">${l}</span>` : l.replace(/(\s#\s.*)$/, '<span class="tk-c">$1</span>').replace(/(&quot;[^&]*?&quot;)/g, '<span class="tk-s">$1</span>'))).join('\n');
  }
  out = out.replace(/(&#39;[^\n]*?&#39;|&quot;[^\n]*?&quot;|'[^'\n]*'|"[^"\n]*")/g, '\u0001$1\u0002');
  out = out.replace(/(^|[^:])(\/\/[^\n]*|#[^\n]*)/gm, (m, a, b) => (b.includes('\u0001') ? m : `${a}<span class="tk-c">${b}</span>`));
  out = out.replace(/\u0001/g, '<span class="tk-s">').replace(/\u0002/g, '</span>');
  out = out.replace(/(\$[a-zA-Z_]\w*)/g, '<span class="tk-v">$1</span>');
  return out.replace(kw, (m, k, off, str) => (/tk-[sc]"[^<]*$/.test(str.slice(0, off)) ? m : `<span class="tk-k">${m}</span>`));
}

function inline(text) {
  const codes = [];
  let t = text.replace(/`([^`]+)`/g, (_, c) => {
    codes.push(`<code>${esc(c)}</code>`);
    return `\u0003${codes.length - 1}\u0003`;
  });
  t = esc(t);
  t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/(^|[\s(])\*([^*\n]+)\*(?=[\s).,;:]|$)/g, '$1<em>$2</em>');
  t = t.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, label, href) => `<a href="${esc(mapLink(href.replace(/&amp;/g, '&')))}">${label}</a>`);
  return t.replace(/\u0003(\d+)\u0003/g, (_, i) => codes[+i]);
}

function mapLink(href) {
  if (/^(https?:|mailto:|#)/.test(href)) return href;
  const [path, hash] = href.split('#');
  const file = path.replace(/^(\.\/|\.\.\/|docs\/)+/, '').toLowerCase();
  if (bySource.has(file)) return `/docs/${bySource.get(file)}.html${hash ? `#${hash}` : ''}`;
  if (path.endsWith('/') || path === '') return path || `#${hash}`;
  return `${cfg.githubUrl}/blob/main/${path.replace(/^\.\.\//, '')}`;
}

function markdown(src) {
  const lines = src.replace(/\r\n/g, '\n').split('\n');
  const html = [];
  const toc = [];
  let i = 0;
  let title = '';
  const isTableSep = (l) => /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(l);
  const cells = (l) => l.trim().replace(/^\||\|$/g, '').split(/(?<!\\)\|/).map((c) => c.trim().replace(/\\\|/g, '|'));

  while (i < lines.length) {
    const line = lines[i];
    let m;
    if ((m = line.match(/^```(\w*)/))) {
      const buf = [];
      i++;
      while (i < lines.length && !lines[i].startsWith('```')) buf.push(lines[i++]);
      i++;
      const lang = m[1] || 'text';
      html.push(`<div class="code" data-lang="${esc(lang)}"><button class="copy" type="button" aria-label="Copy code">Copy</button><pre><code>${highlight(buf.join('\n'), lang)}</code></pre></div>`);
    } else if ((m = line.match(/^(#{1,4})\s+(.*)$/))) {
      const level = m[1].length;
      const text = m[2].replace(/\s*\{#.*\}$/, '');
      const id = slugify(text);
      if (level === 1 && !title) title = text.replace(/`/g, '');
      else if (level <= 3) toc.push({ level, id, text: text.replace(/`/g, '') });
      html.push(level === 1 && title === text.replace(/`/g, '') ? '' : `<h${level} id="${id}"><a class="anchor" href="#${id}" aria-label="Link to section">#</a>${inline(text)}</h${level}>`);
      i++;
    } else if (/^\s*(---|\*\*\*)\s*$/.test(line)) {
      html.push('<hr>');
      i++;
    } else if (line.trim().startsWith('|') && i + 1 < lines.length && isTableSep(lines[i + 1])) {
      const head = cells(line);
      i += 2;
      const rows = [];
      while (i < lines.length && lines[i].trim().startsWith('|')) rows.push(cells(lines[i++]));
      html.push(`<div class="table-wrap"><table><thead><tr>${head.map((h) => `<th>${inline(h)}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
    } else if (/^>\s?/.test(line)) {
      const buf = [];
      while (i < lines.length && /^>\s?/.test(lines[i])) buf.push(lines[i++].replace(/^>\s?/, ''));
      html.push(`<blockquote>${inline(buf.join(' '))}</blockquote>`);
    } else if (/^(\s*)([-*]|\d+\.)\s+/.test(line)) {
      const ordered = /^\s*\d+\./.test(line);
      const items = [];
      while (i < lines.length && /^(\s*)([-*]|\d+\.)\s+/.test(lines[i])) {
        const mm = lines[i].match(/^(\s*)([-*]|\d+\.)\s+(.*)$/);
        let body = mm[3];
        const task = body.match(/^\[( |x)\]\s+(.*)$/i);
        body = task ? `<input type="checkbox" disabled ${task[1].toLowerCase() === 'x' ? 'checked' : ''}> ${inline(task[2])}` : inline(body);
        items.push(`<li${mm[1].length >= 2 ? ' class="sub"' : ''}>${body}</li>`);
        i++;
        while (i < lines.length && /^\s{2,}\S/.test(lines[i]) && !/^(\s*)([-*]|\d+\.)\s+/.test(lines[i])) {
          items[items.length - 1] = items[items.length - 1].replace(/<\/li>$/, ` ${inline(lines[i].trim())}</li>`);
          i++;
        }
      }
      html.push(`<${ordered ? 'ol' : 'ul'}>${items.join('')}</${ordered ? 'ol' : 'ul'}>`);
    } else if (line.trim() === '') {
      i++;
    } else {
      const buf = [];
      while (i < lines.length && lines[i].trim() !== '' && !/^(```|#{1,4}\s|>|\s*([-*]|\d+\.)\s+|\s*\|)/.test(lines[i])) buf.push(lines[i++]);
      if (buf.length === 0) buf.push(lines[i++]);
      html.push(`<p>${inline(buf.join(' '))}</p>`);
    }
  }
  return { html: html.join('\n'), toc, title };
}

// ---------------------------------------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------------------------------------
const tpl = (name) => readFileSync(join(here, 'src', name), 'utf8');
const fill = (text, vars) => {
  let out = text;
  for (let pass = 0; pass < 3; pass++) out = out.replace(/\{\{(\w+)\}\}/g, (m, k) => (k in vars ? vars[k] : m));
  return out;
};
const partial = (n) => readFileSync(join(here, 'src', 'partials', n), 'utf8');

const NAV = [
  ['Home', '/#home'],
  ['Documentation', '/docs/introduction.html'],
  ['Architecture', '/#architecture'],
  ['Guides', '/#learn'],
  ['Examples', '/#examples'],
  ['CLI', '/docs/cli.html'],
  ['API', '/docs/gateway.html'],
];

function navHtml(current) {
  const links = NAV.map(([label, href]) => `<li><a href="${href}"${current === label ? ' aria-current="page"' : ''}>${label}</a></li>`).join('');
  const gh = cfg.githubUrl ? `<li><a href="${esc(cfg.githubUrl)}" rel="noopener">GitHub</a></li>` : '';
  return `${links}${gh}`;
}

const common = () => ({
  name: cfg.name,
  company: esc(cfg.company),
  developer: esc(cfg.developer),
  year: String(cfg.year),
  site: cfg.siteUrl,
  github: esc(cfg.githubUrl),
  githubIssues: esc(`${cfg.githubUrl}/issues`),
  version: FRAMEWORK_VERSION,
  nodeMin: RUNTIME.node.min,
  npmMin: RUNTIME.npm.min,
  phpMin: RUNTIME.php.min,
  composerMin: RUNTIME.composer.min,
  laravel: RUNTIME.laravel,
  nx: RUNTIME.nx,
  kafka: esc(RUNTIME.kafka),
  redis: esc(RUNTIME.redis),
  postgres: esc(RUNTIME.postgres),
  phpExt: RUNTIME.phpExtensions.join(', '),
  nodeTested: RUNTIME.node.tested.join(', '),
  phpTested: RUNTIME.php.tested.join(', '),
  nav: navHtml(''),
  header: partial('header.html'),
  footer: partial('footer.html'),
  searchDialog: partial('search.html'),
  logo: partial('logo.svg'),
});

rmSync(dist, { recursive: true, force: true });
mkdirSync(join(dist, 'docs'), { recursive: true });
cpSync(join(here, 'src', 'assets'), join(dist, 'assets'), { recursive: true });

// --- docs pages -------------------------------------------------------------------------------------
const groups = [...new Set(PAGES.map((p) => p[0]))];
const searchIndex = [];
const sidebar = (active) =>
  groups
    .map(
      (g) =>
        `<div class="side-group"><h2>${esc(g)}</h2><ul>${PAGES.filter((p) => p[0] === g)
          .map(([, t, , slug]) => `<li><a href="/docs/${slug}.html"${slug === active ? ' aria-current="page"' : ''}>${esc(t)}</a></li>`)
          .join('')}</ul></div>`,
    )
    .join('');

PAGES.forEach(([group, label, src, slug], idx) => {
  const file = join(root, src);
  if (!existsSync(file)) throw new Error(`docs source missing: ${src}`);
  const { html, toc, title } = markdown(readFileSync(file, 'utf8'));
  const prev = PAGES[idx - 1];
  const next = PAGES[idx + 1];
  const plain = html.replace(/<[^>]+>/g, ' ').replace(/&[a-z]+;/g, ' ').replace(/\s+/g, ' ').trim();
  searchIndex.push({ t: label, g: group, u: `/docs/${slug}.html`, x: plain.slice(0, 6000), h: toc.map((h) => ({ t: h.text, id: h.id })) });
  const editUrl = `${cfg.githubUrl}/blob/main/${src}`;
  const page = fill(tpl('docs.html'), {
    ...common(),
    nav: navHtml('Documentation'),
    title: esc(`${label} — ${cfg.name}`),
    description: esc(plain.slice(0, 155)),
    canonical: `${cfg.siteUrl}/docs/${slug}.html`,
    group: esc(group),
    label: esc(label),
    pageTitle: esc(title || label),
    sidebar: sidebar(slug),
    toc: toc.filter((h) => h.level === 2).map((h) => `<li><a href="#${h.id}">${esc(h.text)}</a></li>`).join(''),
    content: html,
    editUrl: esc(editUrl),
    prev: prev ? `<a class="pn" href="/docs/${prev[3]}.html" rel="prev"><span>Previous</span>${esc(prev[1])}</a>` : '<span></span>',
    next: next ? `<a class="pn next" href="/docs/${next[3]}.html" rel="next"><span>Next</span>${esc(next[1])}</a>` : '<span></span>',
  });
  writeFileSync(join(dist, 'docs', `${slug}.html`), page);
});
writeFileSync(join(dist, 'search-index.json'), JSON.stringify(searchIndex));

// --- landing page -----------------------------------------------------------------------------------
const landing = fill(tpl('index.html'), {
  ...common(),
  title: esc(`${cfg.name} — Nx + NestJS-style + Laravel + Kafka Microservice Framework`),
  canonical: cfg.siteUrl + '/',
});
writeFileSync(join(dist, 'index.html'), landing);

// --- 404, robots, sitemap, security headers ---------------------------------------------------------------
writeFileSync(join(dist, '404.html'), fill(tpl('404.html'), { ...common(), title: `Not found — ${cfg.name}` }));
writeFileSync(join(dist, 'robots.txt'), `User-agent: *\nAllow: /\nSitemap: ${cfg.siteUrl}/sitemap.xml\n`);
const urls = ['/', ...PAGES.map((p) => `/docs/${p[3]}.html`)];
writeFileSync(
  join(dist, 'sitemap.xml'),
  `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls.map((u) => `  <url><loc>${cfg.siteUrl}${u}</loc></url>`).join('\n')}\n</urlset>\n`,
);
// Apache (cPanel-style shared hosting): caching + security headers + custom 404. Ignored by other servers.
writeFileSync(
  join(dist, '.htaccess'),
  `ErrorDocument 404 /404.html
Options -Indexes
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "DENY"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
  Header always set Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"
  <FilesMatch "\\.(css|js|svg|woff2)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml
</IfModule>
`,
);
console.log(`site built → ${dist}  (${urls.length} pages, search index ${(JSON.stringify(searchIndex).length / 1024).toFixed(0)} kB)`);

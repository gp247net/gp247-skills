> 🌐 **Language:** [🇻🇳 Tiếng Việt](./README_vi.md) · 🇬🇧 English (current)

# GP247 Agent Skills (gp247-skills)

## Introduction

This is the shared GP247 repository of Agent Skills (`SKILL.md`). This page is the
**index**: it lists every skill in the repo and links to each one. All skills follow
the GP247 skill standard (`247-skill`) — English throughout, a mandatory trigger
`description`, and a "Skill info" block with a last-updated date.

## Skill list

| Skill | Summary | Last updated |
| --- | --- | --- |
| [gp247-plugin-create](./skills/gp247-plugin-create/SKILL.md) | Scaffold and build a brand-new GP247 plugin (TailAdmin + Livewire admin, including payment / shipping / coupon plugins), update-safe. | 2026-10-03 |
| [gp247-plugin-v1-to-v2](./skills/gp247-plugin-v1-to-v2/SKILL.md) | Convert a GP247 plugin written for Core 1.x to the v2 plugin format, running on the core the site has. | 2026-10-03 |
| [gp247-template-create](./skills/gp247-template-create/SKILL.md) | Scaffold and build a brand-new GP247 storefront template (theme), update-safe. | 2026-10-03 |
| [gp247-extension-lifecycle](./skills/gp247-extension-lifecycle/SKILL.md) | Install, enable/disable, upgrade, apply data updates, and uninstall existing GP247 plugins & templates from the CLI (`gp247:ext-*`). | 2026-10-03 |

## Skills detect the core version themselves

The skills **do not hardcode** a gp247/core version. The first step of every skill runs the read-only
script `scripts/gp247-probe.php` on the site itself:

```bash
php <skill-dir>/scripts/gp247-probe.php
```

It prints one JSON object with:

- the core version **exactly as core reads it when checking extension compatibility**
  (`config('gp247.core')`), plus the `requireCore` value to write into `gp247.json`;
- whether `gp247/front` and `gp247/shop` are usable — judged by their code **and** their database tables;
- the `gp247:*` commands the site has;
- the capabilities (features, extension points) its core/front/shop offer.

The skill branches on these facts. The same skill therefore works on any core version and does not go
stale each time core is released. The four skills carry four **identical** copies of the script so each
skill still works when installed alone; when you change the script, change all four.

---

<sub>📅 **Last updated:** 2026-10-03 · ✍️ **Author:** GP247</sub>

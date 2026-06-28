# Janitor

Automated, rules-based discussion housekeeping for **Flarum 2**.

Define rules that periodically look inside chosen tags for discussions matching
conditions (age, tags, reply count) and then **hide**, **lock**, **retag / move**,
or **delete** them — so a busy section keeps itself tidy without manual moderator
work.

> Inspired by the "auto-archive old/prefixed threads" plugins from other forum
> software, rebuilt the Flarum way (tags instead of forum IDs).

## Features

- **Rules** — each rule has a *scope* (one or more tags, or all), *conditions*,
  an *action*, and its own *run frequency*.
- **Conditions** — inactive/created for N days, also has / doesn't have certain
  tags, minimum / maximum replies.
- **Actions** — hide, lock, unlock, add tag, remove tag, move (retag), or
  permanently delete.
- **Scheduler** — due rules run automatically; you can also **Run** or
  **Preview** any rule on demand from the admin page.
- **Global dry-run** — preview everything: rules log what they *would* do and
  change nothing. Flip it off when you're confident.
- **Audit-log friendly** — every live action dispatches the matching Flarum
  domain event (`Hidden`, `Deleted`, tag/lock events), so audit-log /
  action-log extensions record Janitor's actions automatically, attributed to an
  admin. (Dry-run previews never emit them.)
- **Safety** — stickied and locked discussions are never touched, every run is
  capped (default 100 actions), permanent delete is opt-in per rule, and every
  (would-be) action is written to an **action log**.

## Install

```bash
composer require ernestdefoe/janitor:"*"
php flarum migrate
php flarum cache:clear
```

Then enable **Janitor** in the admin panel and open its settings to add rules.

## Requirements: the scheduler

Janitor's automatic runs rely on Flarum's task scheduler, so your server must run
this cron entry (once a minute):

```cron
* * * * * cd /path/to/forum && php flarum schedule:run >> /dev/null 2>&1
```

Without it, rules won't run on their own — but the **Run** / **Preview** buttons
on the settings page work regardless, and are the safest way to try a rule first.

## Tips

- Start with **Global dry-run** on and use **Preview** to see exactly which
  discussions a rule would hit (check the action log) before letting it act.
- Prefer **Hide** or **Move** over **Delete** — hidden discussions can be
  restored; deletion can't.
- "Inactive for N days" measures last activity by default — ideal for archiving
  stale threads in a busy tag.

## License

[MIT](LICENSE.md) © ernestdefoe

# Sim Central Suite - A [Nova](https://anodyne-productions.com/nova) Extension

<p align="center">
  <a href="https://github.com/reecesavage/nova-ext-sim-central"><img src="https://img.shields.io/badge/Version-v0.1.0-orange.svg"></a>
  <a href="http://www.anodyne-productions.com/nova"><img src="https://img.shields.io/badge/Nova-v2.7.19+-orange.svg"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-v8.x-blue.svg"></a>
  <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/license-MIT-red.svg"></a>
</p>

A single extension that bundles every Sim Central extension behind one admin page. Toggle each feature on or off, configure them in one place, and let the suite manage the database columns and the controller/model shims so you don't have to install or update each one separately.

This release (v0.1.0) ships the foundation plus the **Display Name** feature. Subsequent releases add Anti Spam Questions, Mission Post Summary, URL Parser, and Ordered Mission Posts.

This extension requires:

- Nova 2.7.19+
- Nova Extension [`jquery`](https://github.com/jonmatterson/nova-ext-jquery) (still required separately)
- Nova Extension [`timepicker`](https://github.com/jonmatterson/nova-ext-timepicker) (only needed once Ordered Mission Posts is enabled in the suite)

## Installation

- Copy the entire directory into `application/extensions/nova_ext_sim_central`.
- Add the following to `application/config/extensions.php`:
```
$config['extensions']['enabled'][] = 'nova_ext_sim_central';
```
- Navigate to your Admin Control Panel and choose **Sim Central Suite** under Manage Extensions.

### Migrating from the standalone extensions

If a standalone equivalent is already enabled in `application/config/extensions.php` (e.g. `nova_ext_display_name`), the suite will refuse to enable that feature until you disable the standalone there. The suite cannot safely take ownership of injected code that the standalone manages.

To migrate:

1. Open `application/config/extensions.php` and comment out (or remove) the standalone's enable line.
2. Reload the suite's admin page and toggle the matching feature ON. The suite will install its own database columns and shim block.
3. Once everything is working, the standalone extension folder can be removed.

## Features

Each feature is independent. Toggling one ON does not enable the others.

- **Display Name** - Use a custom display name on the manifest in place of First/Last/Suffix when one is set.

(More features follow in subsequent releases.)

## Issues

Report bugs or feature requests at the issue tracker: https://github.com/reecesavage/nova-ext-sim-central/issues

## License

Copyright (c) 2026 Reece Savage.

This module is open-source software licensed under the **MIT License**. The full text of the license may be found in the `LICENSE` file.

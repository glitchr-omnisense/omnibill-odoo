# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release. Omnibill Odoo: Odoo's accounting (Odoo 19 and later, Online or self-hosted) for omnisense/omnibill's journal-entries and contacts contracts - entries posted to a journal with their accounts, partners and VAT lines, then posted and read back with their number; customers and suppliers created, updated, read, listed - through Odoo's external JSON-2 API with a user's API key (XML-RPC and JSON-RPC, removed in Odoo 22, are never called).

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnibill: ^1.1`: stable versions, so `composer require omnibill/odoo` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).

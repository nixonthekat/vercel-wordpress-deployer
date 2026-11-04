# Vercel Deployer

Free and open source WordPress plugin that deploys a Vercel project when selected content changes, or when you deploy manually.

Author: [nixonthekat](https://github.com/nixonthekat)

License: [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer
- A Vercel deploy hook URL

## Install

1. Copy this folder into `wp-content/plugins/`.
2. Activate **Vercel Deployer** in WordPress.
3. Open **Settings**, then **Vercel Deployer**.

## Settings

Paste the deploy hook URL from your Vercel project settings. Choose which content types should trigger a deployment. Posts and pages start selected. Products appear in the list when WooCommerce is active.

A deployment runs when published content of a selected type is created, updated, unpublished, or trashed. Drafts and autosaves do not deploy. Saves that land together are sent as one deployment.

An access token is optional. With a token, the plugin follows the build and adds a **See Deployment** link. Team projects also need the team slug, or a team ID that starts with `team_`. Without a token, a successful hook still reports that the deployment was queued.

The token field stays empty after you save. WordPress confirms a stored token with the last four characters. Leave the field blank to keep the saved token.

## Deploy

Use **Deploy** in the top admin bar, then **Deploy now**. The same button is on the settings screen. **Settings** in that menu opens this screen.

Toasts report when a deployment starts, when it is queued or building, and when it succeeds or fails.

Only administrators can open the settings and deploy manually. The hook URL stays on the server and is only sent to Vercel.

#!/bin/bash
# Azure App Service custom startup step (blessed PHP Linux image). Points
# nginx's docroot at public/ to match the project's web-root boundary -
# everything outside public/ (composer.json, src/, database/, vendor/)
# must not be reachable by URL. This runs BEFORE Azure appends its own
# "; php-fpm;" to whatever Startup Command is configured, so this script
# must not start php-fpm itself - just edit the config and exit.
sed -i 's#root /home/site/wwwroot;#root /home/site/wwwroot/public;#' /etc/nginx/sites-available/default

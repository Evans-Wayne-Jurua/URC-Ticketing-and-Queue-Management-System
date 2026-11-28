#!/bin/bash
# Azure App Service custom startup step (blessed PHP Linux image). Points
# nginx's docroot at public/ to match the project's web-root boundary -
# everything outside public/ (composer.json, src/, database/, vendor/)
# must not be reachable by URL. This runs BEFORE Azure appends its own
# "; php-fpm;" to whatever Startup Command is configured, so this script
# must not start php-fpm itself - just edit the config and exit.
#
# On a cold container, this script can run before the base image has
# finished writing its own default nginx config file - seen directly via
# log streaming as "sed: can't read /etc/nginx/sites-available/default"
# on some restarts but not others. Wait for it to exist first instead of
# assuming it's already there.
NGINX_CONF=/etc/nginx/sites-available/default
for i in $(seq 1 30); do
  [ -f "$NGINX_CONF" ] && break
  sleep 0.5
done

if [ -f "$NGINX_CONF" ]; then
  sed -i 's#root /home/site/wwwroot;#root /home/site/wwwroot/public;#' "$NGINX_CONF"
fi

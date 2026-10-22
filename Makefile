.PHONY: test lint build build-js build-css deploy-dry-run deploy-ftp migrate

NODE := node
NPM := npm

lint:
	php -l config/db_host.php
	php -l config/app.php
	php -l src/ChurchTenant.php
	php -l src/DemoAccess.php
	php -l src/PwaManifest.php
	php -l src/Brand.php
	php -l src/AppNav.php
	php -l src/load_modules.php
	php -l src/Domain.php
	php -l src/HomeGroup.php
	php -l src/OsmDistrictCatalog.php
	php -l src/Districts.php
	php -l src/Migrate.php
	php -l src/HomeGroupCatalog.php
	php -l src/ChurchLanding.php
	php -l src/ChurchLandingEditor.php
	php -l src/Knowledge.php
	php -l src/SystemGuide.php
	php -l src/Miracles.php
	php -l src/PrayerPublic.php
	php -l src/Mail.php
	php -l src/RolePreview.php
	php -l src/bootstrap.php
	php -l public/index.php
	php -l bin/send-notifications.php
	php -l bin/send-emails.php
	php -l bin/send-bot-notifications.php
	php -l bin/verify-dashboard-stats.php
	php -l bin/verify-schema.php
	php -l bin/migrate.php
	php -l src/Account.php
	php -l src/Bots.php
	php -l src/Care.php
	php -l src/McpApiKeys.php
	php -l src/McpTools.php
	php -l src/Mcp.php
	php -l src/McpOAuth.php
	php -l src/McpOAuthStore.php
	php -l src/McpOAuthEndpoints.php
	php -l scripts/deploy/paths.php
	php -l scripts/deploy/ftp-deploy.php
	node --check public/assets/app.js

build: build-css build-js

build-js:
	$(NPM) run build:js

build-css:
	$(NPM) run build:css

test: lint
	php tests/run.php
	node tests/js/phone-mask-verify.mjs
	node tests/js/homegroup-filters-verify.mjs
	node tests/js/ajax-anchor-navigation-verify.mjs
	node tests/js/districts-map-verify.mjs
	node tests/js/preza-deck-verify.mjs

deploy-dry-run:
	DEPLOY_DRY_RUN=1 php scripts/deploy/ftp-deploy.php

deploy-ftp:
	php scripts/deploy/ftp-deploy.php

migrate:
	php bin/migrate.php

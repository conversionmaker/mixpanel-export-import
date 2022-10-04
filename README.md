Tomorrow Mixpanel Migrationsskript
==================================

## Requirements
* PHP >= 8.0
* memory_limit >= 2048M
* composer

## Installation
* `git clone git@github.com:conversionmaker/tomorrow-one-mixpanel-migration.git`
* `cd tomorrow-one-mixpanel-migration`
* `composer i`
* `touch .env`
* Add environment settings to the `.env` file

## Configuration
* `BASE_URL` Mixpanel endpoint (should always be `https://api-eu.mixpanel.com/import`)
* `PROJECT_ID` The [project ID](https://help.mixpanel.com/hc/en-us/articles/115004490503-Project-Settings) of the Mixpanel project data should be imported to
* `SA_USERNAME` Username of the [Service Account](https://developer.mixpanel.com/reference/service-accounts)
* `SA_PASSWORD` Password of the Service Account

## Execution
Run following command from the project directory:
```
 bin/console cm:migrate PATH_TO_EXPORTED_JSON
```
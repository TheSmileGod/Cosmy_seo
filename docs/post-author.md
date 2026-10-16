# CS-497: publication author

The WordPress plugin's `cosmy_settings.cosmy_user_id` is the author selected in its settings. New `/cosmy/v1/article` publications now use that ID. An explicitly empty, malformed or nonexistent ID returns `invalid_post_author` (HTTP 400) before insertion; test mode cleans its temporary attachment through the existing cleanup path.

Existing article updates retain their current author even when the selection changes or becomes invalid. No request payload field or API contract has been added. If the settings key is absent, the previous current-user/user-1 policy is retained, but the resolved user must exist. No new fallback policy is introduced.

Run `php tests/post-author.php`, `php -l cosmy_seo/jt_api.php` and `git diff --check`. The tests exercise the real callback with WordPress boundary doubles. Runtime acceptance and correction of existing records are separate operations.

The Sredotochie installation was verified against source commit `8633db7` and release 3.0.11 before the narrow file patch. This change is prepared for maintainer review. It does not publish a ZIP or update other websites; a subsequent general plugin release must include it to preserve the fix.

Sergey selected `Редакция COSMY News` as the public name of the generated service editor: COSMY News is the editorial brand, while COSMY Site is the publishing service. New `cosmy_seo_editor` accounts receive that `display_name` when inserted. The current Sredotochie editor was corrected separately through WordPress. Existing-account migration for other installations remains with the release maintainer: change only the original technical display name and preserve customized names, login/slug and the selected author. The current activation function returns early when the account already exists, so this creation default alone does not migrate those accounts.

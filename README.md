Plugin for COSMY.

/info returns plugin_version from the existing Version header in cosmy_seo/index.php. Cached site-info responses also receive the current header value on every request, so an old transient does not hide a newly installed version. No second version constant is maintained.

<?php

/*
Plugin Name: COSMY SEO
Text Domain: cosmy-seo
Description: COSMY Site — SEO module via REST API.
Version: 2.2.0
Author: COSMY Site 
Author URI: https://cosmy.site
Update URI: https://github.com/TheSmileGod/Cosmy_seo
*/

const COSMY_DEFAULT_CSS = <<<CSS
/* Свернутый вид с плавным затуханием внизу */
body.tag .archive-description{
  max-height: 350px;
  overflow: hidden;
  position: relative;
}
body.tag .archive-description::after{
  content:"";
  position:absolute; left:0; right:0; bottom:0; height:64px;
  background:linear-gradient(to bottom, rgba(255,255,255,0) 0%, #fff 100%);
  pointer-events:none;
  transition:opacity .25s ease;
}
body.tag .archive-description.is-expanded{
  max-height:none; overflow:visible;
}
body.tag .archive-description.is-expanded::after{
  opacity:0;
}

/* Наша уникальная кнопка */
.cosmy-archive-toggle{
  display:none; /* покажем JS-ом */
  margin-top: 35px;
  padding: 6px 15px;
  border: 1px solid #e5e7eb !important;
  background: #e6e6e6 !important;
  color: #636363 !important;
  border-radius: 10px;
  font: inherit;
  cursor: pointer;
  box-shadow: none!important;
  -webkit-appearance: none;
  appearance: none;
}
/* показываем только когда нужно */
.cosmy-archive-toggle.is-visible{
  display:inline-flex;
}

/* Ховеры безопасно только на устройствах с реальным hover */
@media (hover:hover){
  .cosmy-archive-toggle:hover{
    border-color:#d1d5db;
    background:#eee;
    color:#636363;
  }
}
/* Конец - Свернутый вид с плавным затуханием внизу */
CSS;

register_activation_hook(__FILE__, function() {
    if (!get_site_option('cosmy_custom_css')) {
        add_site_option('cosmy_custom_css', "");
    }
});

include_once 'jt_theme.php';
include_once 'jt_admin.php';
include_once 'jt_api.php';

<?php
//Проверим, не запускался ли данный скрипт ранее:
if (defined("WP_DOMAINS_INITIALIZED")) return;
define("WP_DOMAINS_INITIALIZED", 1);

//Домены на котором может работать сайт, локальные и удалённые,
//*********************************
//*** УКАЖИТЕ ЗДЕСЬ СВОИ ДОМЕНЫ ***
//*********************************
$domains_enabled = array(
    "mysite.ru",
    "www.mysite.ru",
    "mysite.local",
    "www.mysite.local"
);


//Добавим в массив $_SERVER все необходимые для проверки ключи,
//которых там может не быть
$_SERVER = array_replace(
    array(
        "HTTPS"=>"", "HTTP_HTTPS"=>"", "REQUEST_SCHEME"=>"", "SERVER_PORT"=>""
    ),
    $_SERVER
);

//Проверим, используется ли https?
$https =
    ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off')
        || (!empty($_SERVER['HTTP_HTTPS']) && $_SERVER['HTTP_HTTPS'] != 'off')
        || $_SERVER['REQUEST_SCHEME'] == 'https'
        || $_SERVER['SERVER_PORT'] == 443) ? true : false;

//Составим адрес хоста
$site_addr = ($https ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . "/";

//Заменим адрес хоста на тот, что мы вычислили
if (!defined("WP_HOME")) define("WP_HOME", $site_addr);
if (!defined("WP_SITEURL")) define("WP_SITEURL", $site_addr);

//Добавим обработчик выдачи сайта, который будет заменять во всех ссылках
//домены на текущий домен
ob_start(function($data) use ($domains_enabled, $https) {
    $current_host = $_SERVER['HTTP_HOST'];
    $replace = array();
    $scheme = $https ? "https" : "http";
    foreach($domains_enabled as $domain)
    {
        if ($current_host == $domain) continue;
        $replace["http://$domain/"] = "$scheme://$current_host/";
        $replace["https://$domain"] = "$scheme://$current_host";
        $replace["//$domain/"] = "//$current_host/";
        $replace["//$domain"] = "//$current_host";
    }
    return str_replace(array_keys($replace), array_values($replace), $data);
});

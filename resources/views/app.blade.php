<!DOCTYPE html>
<html
    lang="uz"
    class="layout-navbar-fixed layout-menu-fixed layout-compact"
    dir="ltr"
    data-assets-path="/assets/"
    data-template="vertical-menu-template"
    data-bs-theme="light"
>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ config('app.name') }}</title>
        <link rel="icon" type="image/x-icon" href="/assets/img/favicon/favicon.ico" />
        <link rel="stylesheet" href="/assets/vendor/fonts/iconify-icons.css" />
        <link rel="stylesheet" href="/assets/vendor/css/core.css" />
        <link rel="stylesheet" href="/assets/css/demo.css" />
        <link rel="stylesheet" href="/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
        <link rel="stylesheet" href="/assets/vendor/css/pages/page-auth.css" />
        <link rel="stylesheet" href="/css/zon-admin.css" />
        <link rel="stylesheet" href="/css/brand-override.css" />
        <script src="/assets/vendor/js/helpers.js"></script>
        <script src="/js/zon-theme-boot.js"></script>
        <script src="/assets/js/config.js"></script>
        @vite(['resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>

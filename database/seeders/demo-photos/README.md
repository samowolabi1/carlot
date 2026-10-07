# Demo car photos

`DemoMarketplaceSeeder` uses real photos from here when a folder exists for a car, named `{year}-{make}-{model}`
in lower case with dashes, for example:

    2019-lexus-rx-350/      2018-toyota-camry/      2017-honda-accord/      2016-toyota-corolla/
    2017-toyota-rav4/       2015-toyota-highlander/ 2020-hyundai-elantra/   2018-mercedes-benz-gle/
    2014-toyota-sienna/     2017-kia-sorento/       2018-toyota-land-cruiser-prado/ 2015-toyota-venza/
    2019-toyota-hilux/      2018-honda-cr-v/        2016-lexus-es-350/      2016-ford-edge/

Put up to 12 `.jpg`, `.png` or `.webp` files in each (sorted by name; the first is the cover), then run
`php artisan db:seed --class=DemoMarketplaceSeeder`. Cars without a folder get a drawn placeholder; cars that
already have drawn placeholders get the real photos the next time the seeder runs. Only use photos you own or
that are licensed for commercial use.

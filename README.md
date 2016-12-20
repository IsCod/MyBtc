# BTCC Auto Orders

Btc Auto Orders is auto order for btcchina, If You apple btcc account account key , you can test this auto Orders.
Btc fast is deal But Ltc is slow

## Environment
PHP 5.4 and Linux 2.6 and redis 3.0.1

## Run
###Buy And Sell Ltc
$ php index.php cron trading buyltc
$ php index.php cron trading sellltc

###Buy And Sell Btc
$ php index.php cron trading buybtc
$ php index.php cron trading sellbtc

###Manage Order
$ php index.php cron trading orderManage

or run cron.sh
$ cron.sh
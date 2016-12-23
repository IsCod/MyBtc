#!/bin/bash
function save()
{
	/usr/local/php/bin/php /Users/iscod/Data/MyBtc/index.php cron trading orderManage > /dev/null 2>&1
}

function sell()
{
	/usr/local/php/bin/php /Users/iscod/Data/MyBtc/index.php cron trading sellbtc > /dev/null 2>&1
	/usr/local/php/bin/php /Users/iscod/Data/MyBtc/index.php cron trading sellltc > /dev/null 2>&1
}

function buy()
{
	/usr/local/php/bin/php /Users/iscod/Data/MyBtc/index.php cron trading buybtc > /dev/null 2>&1
	/usr/local/php/bin/php /Users/iscod/Data/MyBtc/index.php cron trading buyltc > /dev/null 2>&1
}

#每分钟内执行次数
for((i = 0; i < 10;  i++))
do
	save
	sell
	buy
	sleep 5s
done
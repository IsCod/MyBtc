#/bin/bash

for((i = 0; i < 10;  i++))
do
	#buy ltc
	/usr/local/php/bin/php /Users/iscod/Data/MyBtc/index.php cron trading buyltc > /dev/null 2>&1
	sleep 1s
	#sell ltc
	/usr/local/php/bin/php /Users/iscod/Data/MyBtc/index.php cron trading sellltc > /dev/null 2>&1
	sleep 1s
	#orderManage
	/usr/local/php/bin/php /Users/iscod/Data/MyBtc/index.php cron trading orderManage > /dev/null 2>&1
	
	sleep 1s
done

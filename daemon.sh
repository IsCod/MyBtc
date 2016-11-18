#/bin/bash

for((i = 0; i < 30;  i++))
do
    #btcltc
    /usr/local/php/bin/php /Users/iscod/Data/MyBtc/index.php cron Trading LtcBtcOrder > /dev/null 2>&1
    sleep 1s
done

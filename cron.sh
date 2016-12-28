#!/bin/bash
function save()
{
	ps -fe|grep orderManage |grep -v grep
	if [[ $? -ne 0 ]];
		then
			echo "OrderManage is start..."
			php /data/web/MyBtc/index.php cron trading orderManage > /dev/null 2>&1
		else
			echo "OrderManage is runing...."
	fi	
}

function sell()
{
	ps -fe|grep orderManage |grep -v grep
	if [[ $? -ne 0 ]];
		then
			echo "SellBtc is start..."
			php /data/web/MyBtc/index.php cron trading sellbtc > /dev/null 2>&1
		else
			echo "SellBtc is runing...."
	fi

	# ps -fe|grep orderManage |grep -v grep
	# if [[ $? -ne 0 ]];
	# 	then
	#		echo "SellLtc is start..."
	# 		php /data/web/MyBtc/index.php cron trading sellltc > /dev/null 2>&1
	# 	else
	# 		echo "SellLtc is runing...."
	# fi
}

function buy()
{
	ps -fe|grep orderManage |grep -v grep
	if [[ $? -ne 0 ]];
		then
			echo "BuyBtc is start..."
			php /data/web/MyBtc/index.php cron trading buybtc > /dev/null 2>&1
		else
			echo "BuyBtc is runing...."
	fi

	ps -fe|grep orderManage |grep -v grep
	if [[ $? -ne 0 ]];
		then
			echo "BuyLtc is start..."
			php /data/web/MyBtc/index.php cron trading buyltc > /dev/null 2>&1
		else
			echo "BuyLtc is runing...."
	fi
}

#每分钟内执行次数
for((i = 0; i < 30;  i++))
do
	save
	sell
	buy
	sleep 2s
done
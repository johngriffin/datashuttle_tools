#!/bin/sh

for dirname in /Users/jg2950/dev/datashuttle_tools/vs3/mappings/files/*
do
	year=$(echo $dirname|awk '{ print substr( $0, length($0) - 3, length($0) ) }')
	echo $year

	for filename in $dirname/*
	do

		newfile=$(echo $filename|awk '{ print substr( $0, length($0) - 13, length($0) - 3 ) }')
	        echo $newfile

		java -jar xlwrap.jar ../../datashuttle_tools/vs3/mappings/vs3_parameterised.trig ./output_n3/$newfile.n3 filename="$filename" year_long="$year" -lang=N3		
	done;
done;

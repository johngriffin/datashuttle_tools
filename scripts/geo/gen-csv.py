#!/usr/bin/env python
import csv
import urllib2
import json
import sys

import shapefile
import geo

header = ['Area Code', 'Unit ID', 'URL', 'Boundary']
col_map = {}

sf = shapefile.Reader('Data/district_borough_unitary_region')
for idx, field in enumerate(sf.fields[1:]):
    col_map[field[0]] = idx

with open('geo.csv', 'wb') as csv_file:
    writer = csv.writer(csv_file)
    writer.writerow(header)

    items = sf.shapeRecords()
    num_items = len(items)

    for idx, item in enumerate(items):
        item_unit_id = item.record[col_map['UNIT_ID']]
        item_id = 'http://data.ordnancesurvey.co.uk/id/70000000000%05d' % (item_unit_id,)
        item_url = 'http://data.ordnancesurvey.co.uk/doc/70000000000%05d.json' % (item_unit_id,)

        try:
            remote = urllib2.urlopen(item_url)
            data = remote.read()
            remote.close()
        except urllib2.URLError:
            print 'Unable to open URL (%s). Skipping...' % (item_url,)
            continue

        try:
            os_info = json.loads(data)
        except simplejson.decoder.JSONDecodeError as err:
            print 'Unable to decode JSON from %s: %s. Skipping...' % (item_url, err)
            continue

        try:
            item_code = os_info[item_id]['http://www.w3.org/2004/02/skos/core#notation'][0]['value']
        except KeyError:
            print 'No item code found in JSON from %s. Skipping...' % (item_url,)
            continue

        boundary = json.dumps({
            'type': 'Polygon',
            'coordinates': [geo.os2latlng(point[0], point[1]) for point in item.shape.points],
        })

        writer.writerow([item_code, item_unit_id, item_url, boundary])
        csv_file.flush()
        print 'Added %s (%d of %d)' % (item_code, idx+1, num_items,)

print 'Finished'

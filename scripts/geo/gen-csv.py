#!/usr/bin/env python
import csv
import urllib2
import json
import sys

import shapefile
import geo

header = ['Area Code', 'Unit ID', 'URL', 'Boundary']
exclude = (30514,)
boundary_dist = 0.005

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
        if item_unit_id in exclude:
            print '%03d/%d: Skipping...' % (idx+1, num_items,)
            continue

        item_id = 'http://data.ordnancesurvey.co.uk/id/70000000000%05d' % (item_unit_id,)
        item_url = 'http://data.ordnancesurvey.co.uk/doc/70000000000%05d.json' % (item_unit_id,)

        # Fetch OS data for this item
        try:
            remote = urllib2.urlopen(item_url)
            data = remote.read()
            remote.close()
        except urllib2.URLError:
            print 'Unable to open URL (%s). Skipping...' % (item_url,)
            continue

        # Parse OS data as JSON
        try:
            os_info = json.loads(data)
        except simplejson.decoder.JSONDecodeError as err:
            print 'Unable to decode JSON from %s: %s. Skipping...' % (item_url, err)
            continue

        # Extract item code from JSON object
        try:
            item_code = os_info[item_id]['http://www.w3.org/2004/02/skos/core#notation'][0]['value']
        except KeyError:
            print 'No item code found in JSON from %s. Skipping...' % (item_url,)
            continue

        sys.stdout.write('%03d/%d: %s %05d, %d vertices...' %
            (idx+1, num_items, item_code, item_unit_id, len(item.shape.points),))
        sys.stdout.flush()

        # Convert easting and northings using OS datum to lat and lng using standard datum
        points = [geo.os2latlng(point[0], point[1]) for point in item.shape.points]

        # Simplify boundary polygon
        points = geo.ramerdouglas(points, boundary_dist)

        # Create boundary GeoJSON
        boundary = json.dumps({'type': 'Polygon', 'coordinates': points})

        # Write row to CSV
        writer.writerow([item_code, item_unit_id, item_url, boundary])
        csv_file.flush()
        print ' %d vertices. Done.' % (len(points),)

print 'Finished'

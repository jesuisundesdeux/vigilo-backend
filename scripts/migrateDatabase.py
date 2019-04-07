#!/bin/python
# -*- coding: UTF-8 -*-

# 2019 - Bruno Adele <brunoadele@gmail.com> #JeSuisUnDesDeux team

"""
Usage:
  get_mysql_init4version.py [-f=<from> | --from=<from>] [-t=<to> | --to=<to>]
  get_mysql_init4version.py (-h | --help)
  get_mysql_init4version.py --version

Options:
  -f=<nb> --from=<from>                     From version
  -t=<to> --to=<to>                         To version
  -h --help                                 Aide
"""

import glob
import os

from docopt import docopt

def convertToIntVersion(version):
  numbers = version.split('.')
  if len(numbers)!=3:
    return None

  intversion = 0
  multi = 1000000 
  for n in numbers:
    intversion += int(n) * multi
    multi = multi / 1000

  return int(intversion)

if __name__ == '__main__':
    opts = docopt(__doc__, version='get_mysql_init4version.py 0.1')
    docopt(__doc__, argv=None, help=True, version=None, options_first=False)

    # Compute migration versions
    fromversion = convertToIntVersion(opts['--from'])
    toversion = convertToIntVersion(opts['--to'])

    searchpath = os.path.join(os.path.dirname(__file__),'../mysql/init')
    files = glob.glob(f'{searchpath}/init-*.sql')
    sqlmigration = ""
    for filename in sorted(files):
      version = filename.replace(f'{searchpath}/',"")
      findversion = convertToIntVersion(version.replace('init-','').replace('.sql',''))
      if findversion>=fromversion and findversion<=toversion:
        with open(filename, 'r') as f:
          sqlmigration += f"\n\n--------------------\n"
          sqlmigration += f"-- {version}\n"
          sqlmigration += f"--------------------\n\n\n"
          sqlmigration += f.read() 

    with open(f'{searchpath}/sql_migration.sql', 'w') as f:
      f.write(sqlmigration)
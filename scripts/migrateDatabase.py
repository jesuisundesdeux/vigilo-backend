#!/usr/bin/python3
# -*- coding: UTF-8 -*-

# 2019 - Bruno Adele <brunoadele@gmail.com> #JeSuisUnDesDeux team
# 2019-2022 - Quentin HESS <quentin.hess@my-heb.eu> #JeSuisUnDesDeux team
"""
Usage:
  migrateDatabase.py [-f=<from> | --from=<from>] [-t=<to> | --to=<to>] [ --sql-path=<sql-path> ] [--test]
  migrateDatabase.py (-h | --help)
  migrateDatabase.py --version

Options:
  -f=<nb> --from=<from>                     From version
  -t=<to> --to=<to>                         To version
  --sql-path=<sql-path>                     SQL scripts Path
  --test                                    Populate datas for unit test
  -h --help                                 Aide
"""

import glob
import os
import natsort

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

    opts = docopt(__doc__, version='migrateDatabase.py 0.3')
    docopt(__doc__, argv=None, help=True, version=None, options_first=False)

    # Compute migration versions
    fromversion = convertToIntVersion(opts['--from'])
    toversion = convertToIntVersion(opts['--to'])

    if opts['--sql-path']:
      searchpath = opts['--sql-path']
    else:
      searchpath = os.path.abspath(os.path.join(os.path.dirname(__file__),'../mysql'))

    files = glob.glob(f'{searchpath}/init/init-*.sql')

    sqlmigration = ""

    pre_sql_file = open(searchpath+"/pre_sql.sql", "r")
    sqlmigration += pre_sql_file.read()

    for initfilename in natsort.natsorted(files):
      initversion = initfilename.replace(f'{searchpath}/init/',"")
      version = initversion.replace('init-','').replace('.sql','')

      numericalversion = convertToIntVersion(version)

      if (numericalversion>fromversion and numericalversion<=toversion) or (fromversion == 2 and numericalversion == 2):
        # Init SQL Database
        with open(initfilename, 'r') as initfile:
          sqlmigration += f"--------------------\n"
          sqlmigration += f"-- MigrateDatabase {version}\n"
          sqlmigration += initfile.read() 

        # Populate datas for unit test
        populatefilename = f'{searchpath}/populate/populate-{version}.sql'
        if opts['--test'] and os.path.exists(populatefilename):
          with open(populatefilename, 'r') as populatefile:
            sqlmigration += f"\n\n--------------------\n"
            sqlmigration += f"-- populate test {version}\n"
            sqlmigration += f"--------------------\n\n\n"
            sqlmigration += populatefile.read() 

    with open(f'{searchpath}/sql_migration.sql', 'w') as f:
      f.write(sqlmigration)

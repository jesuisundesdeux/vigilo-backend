#!/usr/bin/php
<?php
// 2019 - Thibault Coupin <thibault.coupin@gmail.com>
//        0.2 - migration du script python de Bruno en php
// 2019 - Bruno Adele <brunoadele@gmail.com> #JeSuisUnDesDeux team
//        0.1 - script python

// Parsing args
$shortopts  = "";
$shortopts .= "f::";
$shortopts .= "t::";
$shortopts .= "h";
$longopts  = array(
    "from::",
    "to::",
    "test",
    "help",
    "version",
);
$options = getopt($shortopts, $longopts);


if (isset($options['h']) || isset($options['help'])){
    // Help
echo '
    migrationDatabase.php [-f=<from> | --from=<from>] [-t=<to> | --to=<to>] [--test]
    migrationDatabase.php (-h | --help)
    migrationDatabase.php --version
  
  Options:
    -f=<nb> --from=<from>                     From version
    -t=<to> --to=<to>                         To version
    --test                                    Populate datas for unit test
    -h --help                                 Aide
';
    exit(0);
} elseif (isset($options['version'])){
    // Version
    print "migrationDatabase 0.2n\n";
    exit(0);
}

// Compute from and to versions
$from="0.0.0";
$to="99.99.99";

if (isset($options['f'])){
    $from=$options['f'];
} elseif (isset($options['from'])){
    $from=$options['from'];
}

if (isset($options['t'])){
    $to=$options['t'];
} elseif (isset($options['to'])){
    $to=$options['to'];
}


// Compute directories and prepate file
$sql_dir=__DIR__ . "/../mysql";
$init_dir=$sql_dir . "/init";
$populate_dir=$sql_dir . "/populate";
$target_file=$sql_dir . "/sql_migration.sql";
print "Migration from version ".$from." to ".$to."\n";
print "File: ". realpath($target_file)."\n";

$migration="";
$init_files = glob($init_dir."/init-*.sql");
foreach($init_files as $init_file){
    $version = str_replace('init-', '', basename($init_file,'.sql'));
    if (version_compare($version,$from)>=0 && version_compare($to,$version)>=0){
        $migration .= "\n\n--------------------\n";
        $migration .= "-- init ".$version."\n";
        $migration .= "--------------------\n\n\n";
        $migration .= file_get_contents($init_file);

        $populate_file = $populate_dir."/populate-".$version.".sql";
        if (isset($options['test']) && file_exists($populate_file)){
            $migration .= "\n\n--------------------\n";
            $migration .= "-- populate ".$version."\n";
            $migration .= "--------------------\n\n\n";
            $migration .= file_get_contents($populate_file);
        }
    }
}

file_put_contents($target_file,$migration);


?>

<?php
/**
 * Useful functions for LitCalEngine.php
 * @Author: John Romano D'Orazio
 * @Date:   2017-2018
 */

    // https://en.wikipedia.org/wiki/Computus#Anonymous_Gregorian_algorithm
    // aka Meeus/Jones/Butcher algorithm
    
    function calcGregEaster($Y){
      $a = $Y % 19;
      $b = floor($Y/100);
      $c = $Y % 100;
      $d = floor($b / 4);
      $e = $b % 4;
      $f = floor( ($b+8) / 25 );
      $g = floor( ($b-$f+1) / 3 );
      $h = (19*$a + $b - $d - $g + 15) % 30;
      $i = floor($c/4);
      $k = $c % 4;
      $l = (32 + 2*$e + 2*$i - $h - $k) % 7;
      $m = floor( ($a+11*$h+22*$l) / 451 );
      $month = floor( ($h + $l - 7*$m + 114) / 31 );
      $day = ( ($h + $l - 7*$m + 114) % 31) + 1;
  
      $dateObj   = DateTime::createFromFormat('!j-n-Y', $day.'-'.$month.'-'.$Y);
      
      return $dateObj;
    }

    /** 
     * Ordinal Suffix function
     * Useful for choosing the correct suffix for ordinal numbers
     * in the English language
     * @Author: John Romano D'Orazio
    */
    function ordSuffix($ord) {
		$ord_suffix = ''; //st, nd, rd, th
		if(       $ord===1 || ($ord % 10 === 1  && $ord <> 11) ){ $ord_suffix = 'st'; }
		else if(  $ord===2 || ($ord % 10 === 2  && $ord <> 12) ){ $ord_suffix = 'nd'; }
		else if(  $ord===3 || ($ord % 10 === 3  && $ord <> 13) ){ $ord_suffix = 'rd'; }
		else { $ord_suffix = 'th'; }
		return $ord_suffix;
    }
    
    

?>

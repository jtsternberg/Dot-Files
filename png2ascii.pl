#!/usr/bin/perl
#
# Convert a PNG to a simil in ASCII ART
# Calculating the average luminance.
#
# http://people.baicom.com/~agramajo/projects/png2ascii/
# Based on: http://www.codeproject.com/aspnet/ascii_art_with_c_.asp
#
# Dependencies:
# GD.pm - http://search.cpan.org/dist/GD/
#
# Use:
# $ ./png2ascii.pl image.png > image.txt
#
# Author:
# agramajo _at_ gmail _dot_ com
#20050129
#
use GD;

$|++;
$debug = 0;

#
# raise the image
#
$img = newFromPng GD::Image($ARGV[0]) || die;

#
# find the limits
#
($width,$height) = $img->getBounds();

print "($width,$height)\n" if $debug;

#
# loop through the image using pixels ( x , y )
# shrink the image one character per pixel would be a lot
#
for ( $h = 0; $h < $height/10; $h++ ) {
	$starty = $h * 10;
	print "$starty\n" if $debug;
	for ( $w = 0; $w < $width/5; $w++ ) {
		$startx = $w * 5;
		print "$startx\n" if $debug;
		$bri = 0;
		$i = 0;
		for ( $y = 0; $y < 10; $y++ ) {
			for ( $x = 0; $x < 10; $x++ ) {
				$cy = $y + $starty;
				$cx = $x + $startx;
				#
				# find the RGB of the pixel and calculate the Luminance
				# 0 < lum < 255
				# http://www.guides.sk/scantips2/lumin.html
				#
				my $idx = $img->getPixel($cx,$cy);
				my ($r,$g,$b) = $img->rgb($idx);
				my $lum = 0.3 * $r + 0.59 * $g + 0.11 * $b;
				print "($cx,$cy)\t$idx $r $g $b $lum\n" if $debug;
				#
				# the idea is to accumulate and then take an average
				#
				$i++;
				$bri += $lum;
			}
		}

		# here we get the average luminance
		$sb = int($bri / $i);

		# print the characters according to that luminance
 		if ($sb < 25) { print '#' }
 		elsif ($sb < 50) { print '@' }
 		elsif ($sb < 75) { print '0' }
 		elsif ($sb < 100) { print '$' }
 		elsif ($sb < 125) { print '&' }
 		elsif ($sb < 150) { print '¤' }
 		elsif ($sb < 175) { print '~' }
 		elsif ($sb < 200) { print '·' }
 		elsif ($sb < 225) { print '¨' }
 		elsif ($sb < 250) { print '´' }
 		else { print ' ' }
	}
 	print "\n";
}
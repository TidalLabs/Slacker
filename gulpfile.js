var gulp = require('gulp');
var run = require('gulp-run');

gulp.task('build', function () {
	$cmd = new run.Command('php ./bin/phar-composer.phar build', {});
    $cmd.exec('composer.json slacker.phar');
});

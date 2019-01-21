/**
 * Gruntfile.js
 *
 * Minify javascript and css files
 *
 */
module.exports = function(grunt) {

  grunt.initConfig({
    pkg: grunt.file.readJSON('package.json'),
    uglify: {
      options: {
        mangle: false
      },
      dist: {
        files: {
          'assets/wondercms.min.js': 'assets/wondercms.js'
        }
      }
    },
    cssmin: {
      target: {
        files: {
          'assets/wondercms.min.css': [
              'assets/wondercms.css',
              'assets/adminPanel.css'
          ]
        }
      }
    },
  });

  grunt.loadNpmTasks('grunt-contrib-uglify');
  grunt.loadNpmTasks('grunt-contrib-cssmin');

  grunt.registerTask('default', ['uglify', 'cssmin']);
};

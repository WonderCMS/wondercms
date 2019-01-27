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
          'assets/admin.min.js': 'src/js/admin.js'
        }
      }
    },
    cssmin: {
      target: {
        files: {
          'assets/admin.min.css': [
              'src/css/admin.css',
              'src/css/adminPanel.css'
          ]
        }
      }
    },
  });

  grunt.loadNpmTasks('grunt-contrib-uglify');
  grunt.loadNpmTasks('grunt-contrib-cssmin');

  grunt.registerTask('default', ['uglify', 'cssmin']);
};

#!/bin/bash
#set folder permissions for WonderCMS

find ./ -type d -exec chmod 755 {} \;
find ./ -type f -exec chmod 644 {} \;
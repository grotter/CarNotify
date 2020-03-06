#!/bin/sh

rsync -av . grotter@ssh.ocf.berkeley.edu:/home/g/gr/grotter/notify/ --exclude=".*" --exclude="*.sh" --exclude="*.md"

#!/usr/bin/env bash

##############
# This is the full copy of Laravel Framework's scrypt.
# I hope this copying does not violate any rights
##############

set -e
set -x

# Determine the current branch
if [ -z "$1" ]; then
    CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
else
    CURRENT_BRANCH=$1
fi


function split()
{
    echo "Splitting $1..."
    SHA1=`git subtree split --prefix=$1`
    echo "Generated SHA1: $SHA1"

    if [ -z "$SHA1" ]; then
        echo "Error: git subtree returned empty SHA1 for prefix $1"
        exit 1
    fi

    git push $2 "$SHA1:refs/heads/$CURRENT_BRANCH" -f
}

function remote()
{
    git remote add $1 $2 || true
}

git pull origin $CURRENT_BRANCH

remote foundation git@github.com:rabbitevents/foundation.git
remote publisher git@github.com:rabbitevents/publisher.git
remote listener git@github.com:rabbitevents/listener.git

split 'src/RabbitEvents/Foundation' foundation
split 'src/RabbitEvents/Publisher' publisher
split 'src/RabbitEvents/Listener' listener

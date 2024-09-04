#!/bin/bash

function check_error() {
  if [ $? -ne 0 ]; then
      echo "Error"
      exit 1
  fi
}

./vendor/bin/phpstan
check_error

TAG=$(git describe --tags `git rev-list --tags --max-count=1`)
check_error
echo "tag = $TAG"

rm -f *.zip
check_error

git checkout $TAG
check_error

zip -r seowriting.$TAG.zip seowriting/
check_error

git checkout main
check_error

echo ""
echo "all tasks done"
echo ""

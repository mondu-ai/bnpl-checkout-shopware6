#!/bin/bash

helpFunction() {
  echo ""
  echo "Usage: $0 -v version"
  echo "\t-v Version of the plugin"
  exit 1 # Exit script after printing help
}

while getopts "v:o:c:" opt; do
  case "$opt" in
  v) version="$OPTARG" ;;
  o) old_version="$OPTARG" ;;
  c) changes="$OPTARG" ;;
  ?) helpFunction ;; # Print helpFunction in case parameter is non-existent
  esac
done

if ! echo $version | grep -Eq "^[0-9]+\.[0-9]+\.[0-9]+$"; then
  echo "Invalid version: ${version}"
  echo "Please specify a semantic version with no prefix (e.g. X.X.X)."
  exit 1
fi

if ! echo $old_version | grep -Eq "^[0-9]+\.[0-9]+\.[0-9]+$"; then
  echo "Invalid old version: ${old_version}"
  echo "Please specify a semantic version with no prefix (e.g. X.X.X)."
  exit 1
fi

if [[ $changes == "keep" ]]; then
  echo "Changes will be commited and pushed"
else
  echo "Changes won't be commited and pushed"
fi

OLD_TAG_SED_DOT="${old_version//./\\.}"
NEW_TAG_SED_DOT="${version//./\\.}"

files_to_update="composer.json"

for file in $files_to_update; do
  echo "Updating semver occurrences in $file"
  sed -i "s/${OLD_TAG_SED_DOT}/${NEW_TAG_SED_DOT}/g" $file
  if [[ $changes == "keep" ]]; then
    echo "Adding ${file} for new commit"
    git add $file
  fi
done

if [[ $changes == "keep" ]]; then
  echo "Pushing changes to default branch on remote"
  default_branch=$(git remote show origin | sed -n "/HEAD branch/s/.*: //p")

  git commit -m "Updated semver in compose.json"
  git push origin "${default_branch}" -f
fi

echo "Creationg Mond1SW6"
mkdir Mond1SW6
echo "Generating zip file"
rsync -r --exclude 'Mond1SW6' --exclude ".env" --exclude ".env.example" --exclude "activate.sh" --exclude "SHOPWARE.md" --exclude "*.DS_Store" ./* Mond1SW6
zip -r -D "Mond1SW6.zip" Mond1SW6/*    
rm -r Mond1SW6
echo "Done"

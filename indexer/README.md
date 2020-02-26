# Contao Package Indexer

The *Contao Package Indexer* is a Symfony console application that
pushes information about Composer packages to [Algolia]. Algolia is then
used by the [Contao Manager] to search for packages.

**This application is for internal use only and should not**
**be used by anyone but the Contao core team.**


## Setup & Run

The application requires three environment variables to run:

 1. `ALGOLIA_APP_ID` is your Algolia application ID
 2. `ALGOLIA_API_KEY` is your Algolia API key
 2. `ALGOLIA_INDEX` is the name of your Algolia index

Next you can simply run the `index` file in your command line.


[Algolia]: https://www.algolia.com
[Contao Manager]: https://github.com/contao/contao-manager

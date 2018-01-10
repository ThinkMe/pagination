<?php namespace ThinkMe\Pagination;

use Countable;
use ArrayAccess;
use Illuminate\Support\Facades\Input;
use IteratorAggregate;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Arrayable;
use Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator as BasePaginator;

class SimplePaginator extends BasePaginator
{
    protected $app;
    protected $view;
    protected $request;
    protected $viewName;
    protected $baseUrl;
    /**
     * @var \Illuminate\Routing\UrlGenerator
     */
    protected $urlGenerator;

    /**
     * @var \Illuminate\Routing\Router
     */
    protected $router;

    /**
     * @var boolean
     */
    protected $withQuery = true;

    /**
     * Configuration for assigned route
     *
     * @var array
     */
    protected $routeConfig;

    /**
     * Cached pages range
     *
     * @var array
     */
    const PAGE_OPTIMIZE_ON = 'on';
    const PAGE_OPTIMIZE_OFF = 'off';
    const PAGE_OPTIMIZE_AUTO = 'auto';

    protected $pagesRange;
    protected $largePageOptimize = self::PAGE_OPTIMIZE_OFF;
    protected $largePageOptimizeTotal = 100000;
    protected $largePageOptimizeCurrentPage = 1000;
    protected $simple = false;


    /**
     * Create a new paginator instance.
     */
    public function __construct(array $options = [])
    {
        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }

        $this->app = App::getInstance();
        $this->setView($this->app['view']);
        $this->setRouter($this->app['router']);
        $this->setUrlGenerator($this->app['url']);
        $this->setRequest($this->app['request']);
    }

    /**
     * quick paginator.
     *
     * @param $builder
     * @param $perPage
     * @param null $currentPage
     * @param array $options
     * @return Collection|static
     */
    public function paginate($builder, $perPage, $currentPage = null, array $options = [])
    {

        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }

        $results = null;
        switch ($this->largePageOptimize) {
            case self::PAGE_OPTIMIZE_OFF:
                $results = $builder->forPage($this->getCurrentPage($currentPage), $perPage + 1)->get();
                break;
            case self::PAGE_OPTIMIZE_ON:
                $query = clone $builder->getQuery();
                $model = clone $builder->getModel();
                $idResult = $model->setQuery($query)->select('id')->limit($perPage + 1)->offset($this->getCurrentPage($currentPage)-1)->pluck('id');
                $results = $builder->whereIn('id', $idResult->toArray())->get();
                break;
        }

        return $this->make($results, $perPage, $currentPage);
    }

    /**
     * @param $value
     */
    public function setPageOptimize($value) {
        $this->largePageOptimize = strtolower($value);
    }

    /**
     * @param $value
     */
    public function setOptimizeTotal($value) {
        $this->largePageOptimizeTotal = $value;
    }

    /**
     * @param $value
     */
    public function setOptimizeCurrentPage($value) {
        $this->largePageOptimizeCurrentPage = $value;
    }

    /**
     * @param $items
     * @param $perPage
     * @param null $currentPage
     * @param array $options
     * @return object
     */
    public function make($items, $perPage, $currentPage = null, array $options = [])
    {
        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }

        Paginator::currentPageResolver(function () use ($currentPage) {
            return $this->getCurrentPage($currentPage);
        });

        $this->perPage = $perPage;

        $this->currentPage = $this->setCurrentPage($currentPage);

        $this->path = $this->path != '/' ? rtrim($this->path, '/') : Paginator::resolveCurrentPath();

        $this->setItems($items);

        return $this->items;
    }

    /**
     * @param null $currentPage
     * @return mixed|null
     */
    public function getCurrentPage($currentPage = null)
    {
        if ($currentPage != null) {
            return $currentPage;
        }

        if (null === $this->routeConfig) {
            return Input::get($this->getPageName());
        } else {
            return Route::input($this->getPageName());
        }
    }


    /**
     * @param \Illuminate\Routing\UrlGenerator $generator
     */
    public function setUrlGenerator(UrlGenerator $generator)
    {
        $this->urlGenerator = $generator;
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    public function setRouter(Router $router)
    {
        $this->router = $router;
    }

    /**
     * Pass to route query data
     *
     * @return \ThinkMe\Pagination\Paginator
     */
    public function withQuery()
    {
        $this->withQuery = true;

        return $this;
    }

    /**
     * Don't pass query data to generated route
     *
     * @return \ThinkMe\Pagination\Paginator
     */
    public function withoutQuery()
    {
        $this->withQuery = false;

        return $this;
    }

    /**
     * Bind route to generated pagination links
     *
     * @param \Illuminate\Routing\Route|string $route if string route with given name will be used
     * @param array $parameters
     * @param bool $absolute
     * @return \ThinkMe\Pagination\Paginator
     */
    public function route($route, array $parameters = array(), $absolute = true)
    {
        $instance = null;
        $name = $route;

        if (true === is_object($route) && $route instanceof Route) {
            $instance = $route;
            $name = null;
        }

        $this->routeConfig = compact('instance', 'name', 'parameters', 'absolute');

        return $this;
    }

    /**
     * Use current route for generating url
     *
     * @TODO $route->parameters() can throw Exception if it has no parameters defined
     *       it should be handled, but Laravel UrlGenerator can't generate urls with extra params
     *       so maybe it's better to leave it that way.
     * @return \App\Services\Pagination\Paginator
     */
    public function useCurrentRoute()
    {

        //$route = $this->router->current();

        //return $this->route($route, $route->parameters());
    }

    /**
     * Get a URL for a given page number.
     *
     * @param integer $page
     * @return string
     */
    public function url($page)
    {

        if (null === $this->routeConfig) {
            //return parent::url($page);
            if ($page <= 0) $page = 1;

            // If we have any extra query string key / value pairs that need to be added
            // onto the URL, we will put them in query string form and then attach it
            // to the URL. This allows for extra information like sortings storage.
            $parameters = [$this->pageName => $page];

            if (count($this->query) > 0) {
                $parameters = array_merge($this->query, $parameters);
            }

            return $this->getCurrentUrl() . '?'
                . http_build_query($parameters, null, '&')
                . $this->buildFragment();
        }

        $parameters = $this->routeConfig['parameters'];

        //$parameters = [$this->pageName => $page];
        //$this->getRequest()->query()
        if (true === $this->withQuery) {
            $parameters = array_merge($parameters, $this->query);
        }

        $parameters[$this->getPageName()] = $page;

        $absolute = (null === $this->routeConfig['absolute']) ? true : $this->routeConfig['absolute'];

        // allow adding hash fragments to url
        $fragment = $this->buildFragment();

        $generated_route = $this->urlGenerator->route($this->routeConfig['name'], $parameters, $absolute, $this->routeConfig['instance']);

        return $generated_route . $fragment;
    }

    public function setView($view)
    {
        $this->view = $view;
    }

    /**
     * Get the name of the pagination view.
     *
     * @param  string $view
     * @return string
     */
    public function getViewName($view = null)
    {
        if (!is_null($view)) return $view;

        return $this->viewName ?: 'pagination::slider';
    }

    /**
     * Get the pagination view.
     *
     * @param  \Illuminate\Pagination\Paginator $paginator
     * @param  string $view
     * @return \Illuminate\View\View
     */
    public function getPaginationView(Paginator $paginator, $view = null)
    {
        $data = array('environment' => $this, 'paginator' => $paginator);

        return $this->view->make($this->getViewName($view), $data);
    }

    /**
     * Get the pagination links view.
     *
     * @param  string $view
     * @return \Illuminate\View\View
     */
//    public function links($view = null, $data = [])
//    {
//        //dd(\App::VERSION());
//        //return $this->getPaginationView($this, $view);
//        return $this->render($view, $data);
//    }

    /**
     * Set the query string variable used to store the page.
     *
     * @param  string $name
     * @return $this
     */
    public function getPageName()
    {
        return $this->pageName;
    }

    /**
     * Get the active request instance.
     *
     * @return \Symfony\Component\HttpFoundation\Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Set the active request instance.
     *
     * @param  \Symfony\Component\HttpFoundation\Request $request
     * @return void
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Check if can show first page in template
     *
     * @return boolean
     */
    public function canShowFirstPage()
    {
        return false === array_search(1, $this->getPagesRange());
    }

    /**
     * Check if can show last page in template
     *
     * @return boolean
     */
    public function canShowLastPage()
    {
        return false === array_search($this->lastPage(), $this->getPagesRange());
    }

    /**
     * Get the root URL for the request.
     *
     * @return string
     */
    public function getCurrentUrl()
    {
        return $this->baseUrl ?: $this->request->url();
    }

    /**
     * Set the base URL in use by the paginator.
     *
     * @param  string $baseUrl
     * @return void
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }
}

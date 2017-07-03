<?php
namespace Devi\App;

use DateTime;
use Devi\Authorization\AuthorizationInterface;
use Devi\Model\User\User;
use Devi\Model\User\UserRepositoryInterface;
use Silex\Api\ControllerProviderInterface;
use Silex\Application;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Serializer;

class UserControllerProvider implements ControllerProviderInterface
{
  // Variables
  private $authorization;
  private $repository;
  private $serializer;
  
  // Constructor
  public function __construct(AuthorizationInterface $authorization, UserRepositoryInterface $repository, Serializer $serializer)
  {
    $this->authorization = $authorization;
    $this->repository = $repository;
    $this->serializer = $serializer;
  }
  
  // Validate the user
  private function validate($user)
  {
    // Check if the user exists
    if ($user === null)
      throw new ApplicationException('The specified user was not found',404);
  }
  
  // Validate the current user
  public function validateCurrent(User $user, Request $request)
  {
    // Check if the user is the owner of the image
    if ($user->getName() !== $request->request->get('user')->getName())
      throw new ApplicationException('The specified user cannot be changed by this user',403);
  }
  
  // Check the current user
  public function checkCurrent(User $user, $authorized)
  {
    try
    {
      if ($authorized === null)
        return false;
      
      $this->validateCurrent($user,$authorized);
      return true;
    } 
    catch (ApplicationException $ex) 
    {
      return false;
    }
  }
  
  // Get all extisting users
  public function getAll()
  {
    // Return all users
    $json = $this->serializer->serialize($this->repository->findAll(),'json');
    return JsonResponse::fromJsonString($json);
  }
  
  // Create a new user
  public function post(Request $request)
  {
    // Validate the parameters
    if (!$request->request->has('name'))
      throw new ApplicationException('The request did not contain a name',400);
    if (!$request->request->has('email'))
      throw new ApplicationException('The request did not contain an email address',400);
    if (!$request->request->has('password'))
      throw new ApplicationException('The request did not contain a password',400);
  
    // Create the user
    $user = User::create(
      $request->request->get('name'),
      $request->request->get('email'),
      password_hash($request->request->get('password'),PASSWORD_BCRYPT)
    );
  
    // Put the user in the database
    $this->repository->create($user);
    
    // Return the created user
    $json = $this->serializer->serialize($user,'json');
    return JsonResponse::fromJsonString($json,201);
  }
  
  // Get an existing user
  public function get($user)
  {
    // Validate the user
    $this->validate($user);
    
    // Return the user
    $json = $this->serializer->serialize($user,'json');
    return JsonResponse::fromJsonString($json);
  }

  // Patch an existing user
  public function patch($user, Request $request)
  {
    // Validate the user
    $this->validate($user);
    $this->validateCurrent($user,$request);

    // Replace the fields
    if ($request->request->has('name'))
      $user->setFileName($request->request->get('name'));
    if ($request->request->has('email'))
      $user->setEmail($request->request->get('email'));
    if ($request->request->has('password'))
      $user->setPassword($request->request->get('password'));
    if ($request->request->has('public'))
      $user->setPublic($request->request->get('public'));

    // Patch the updated user in the database
    $this->repository->update($user->setModifiedAt(new DateTime));

    // Return the user
    $json = $this->serializer->serialize($user,'json');
    return JsonResponse::fromJsonString($json);
  }
  
  // Delete an existing user
  public function delete($user, Request $request)
  {
    // Validate the user
    $this->validate($user);
    $this->validateCurrent($user,$request);
  
    // Delete the user
    $this->repository->delete($user);
  
    // Return the user
    $json = $this->serializer->serialize($user,'json');
    return JsonResponse::fromJsonString($json);
  }
  
  // Get all images of a user
  public function getAllImages($user, Request $request)
  {
    global $app;
    
    // Validate the user
    $this->validate($user);
    
    // Return the images
    if ($this->checkCurrent($user,$request->request->get('user')))
    {
      $json = $this->serializer->serialize($app['images.repository']->findAllByUser($user),'json');
      return JsonResponse::fromJsonString($json);
    }
    else
    {
      $json = $this->serializer->serialize($app['images.repository']->findAllPublicByUser($user),'json');
      return JsonResponse::fromJsonString($json);
    }
  }
  
  // Connect to the application
  public function connect(Application $app)
  {
    // Create controllers
    $controllers = $app['controllers_factory'];
    
    // Create user collection routes
    $controllers
      ->get('/',[$this,'getAll'])
      ->before([$this->authorization,'optional']);

    // Create user routes
    $controllers
      ->post('/',[$this,'post']);
    $controllers
      ->get('/{user}',[$this,'get'])
      ->convert('user',[$this->repository,'findByName'])
      ->before([$this->authorization,'optional']);
    $controllers
      ->patch('/{user}',[$this,'patch'])
      ->convert('user',[$this->repository,'findByName'])
      ->before([$this->authorization,'authorize']);
    $controllers
      ->delete('/{user}',[$this,'delete'])
      ->convert('user',[$this->repository,'findByName'])
      ->before([$this->authorization,'authorize']);
    
    // Create user images routes
    $controllers
      ->get('/{user}/images/',[$this,'getAllImages'])
      ->convert('user',[$this->repository,'findByName'])
      ->before([$this->authorization,'optional']);
    
    // Return the controllers
    return $controllers;
  }
}
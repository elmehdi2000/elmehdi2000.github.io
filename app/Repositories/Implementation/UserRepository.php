<?php

namespace App\Repositories\Implementation;

use App\Models\UserRoles;
use App\Models\Users;
use Illuminate\Support\Facades\Auth;
use App\Repositories\Implementation\BaseRepository;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Exceptions\RepositoryException;


//use Your Model

/**
 * Class UserRepository.
 */
class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    /**
     * @var Model
     */
    protected $model;

    /**
     * BaseRepository constructor..
     *
     *
     * @param Model $model
     */


    public static function model()
    {
        return Users::class;
    }

    public function createUser(array $attributes)
    {
        $model = $this->model->newInstance($attributes);
        $model->save();
        $this->resetModel();
        $result = $this->parseResult($model);
        foreach ($attributes['roleIds'] as $roleId) {
            $model = UserRoles::create(array(
                'userId' =>   $result->id,
                'roleId' =>  $roleId,
            ));
        }

        return $result;
    }

    public function findUser($id)
    {
        $model = $this->model->with('userRoles')->with('userClaims')->findOrFail($id);
        $this->resetModel();
        return $this->parseResult($model);
    }

    public function updateUser($model, $id, $userRoles)
    {
        $userRoles1 =  UserRoles::where('userId', '=', $id)->get('id');
        UserRoles::destroy($userRoles1);
        $result = $this->parseResult($model);

        foreach ($userRoles as $roleId) {
            UserRoles::create(array(
                'userId' =>   $result->id,
                'roleId' =>  $roleId,
            ));
        }

        $saved = $model->save();
        if (!$saved) {
            throw new RepositoryException('Error in saving data.');
        }
        $this->resetModel();

        $result = $this->parseResult($model);

        return $result;
    }

    public function updateUserProfile($request)
    {
        $userId = Auth::parseToken()->getPayload()->get('userId');
        if ($userId == null) {
            throw new RepositoryException('User does not exist.');
        }

        $model = $this->model->findOrFail($userId);

        $model->firstName = $request->firstName;
        $model->lastName = $request->lastName;
        $model->phoneNumber = $request->phoneNumber;
        $saved = $model->save();
        if (!$saved) {
            throw new RepositoryException('Error in saving data.');
        }
        $this->resetModel();

        $result = $this->parseResult($model);


        return $result;
    }
}

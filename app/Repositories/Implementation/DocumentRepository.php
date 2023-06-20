<?php

namespace App\Repositories\Implementation;

use App\Models\DocumentMetaDatas;
use App\Models\DocumentAuditTrails;
use App\Models\DocumentOperationEnum;
use App\Models\DocumentRolePermissions;
use App\Models\Documents;
use App\Models\DocumentUserPermissions;
use App\Models\UserRoles;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Repositories\Implementation\BaseRepository;
use App\Repositories\Contracts\DocumentRepositoryInterface;
use App\Repositories\Exceptions\RepositoryException;

//use Your Model

/**
 * Class UserRepository.
 */
class DocumentRepository extends BaseRepository implements DocumentRepositoryInterface
{
    /**
     * @var Model
     */
    protected $model;

    /**
     * BaseRepository constructor..
     *
     * @param Model $model
     */


    public static function model()
    {
        return Documents::class;
    }

    public function getDocuments($attributes)
    {

        $query = Documents::select(['documents.id', 'documents.name', 'documents.url', 'documents.createdDate', 'documents.description', 'categories.id as categoryId', 'categories.name as categoryName', DB::raw("CONCAT(users.firstName,' ', users.lastName) as createdByName")])
            ->join('categories', 'documents.categoryId', '=', 'categories.id')
            ->join('users', 'documents.createdBy', '=', 'users.id');

        $orderByArray =  explode(' ', $attributes->orderBy);
        $orderBy = $orderByArray[0];
        $direction = $orderByArray[1] ?? 'asc';

        if ($orderBy == 'categoryName') {
            $query = $query->orderBy('categories.name', $direction);
        } else if ($orderBy == 'name') {
            $query = $query->orderBy('documents.name', $direction);
        } else if ($orderBy == 'createdDate') {
            $query = $query->orderBy('documents.createdDate', $direction);
        } else if ($orderBy == 'createdBy') {
            $query = $query->orderBy('users.firstName', $direction);
        }

        if ($attributes->categoryId) {
            $query = $query->where('categoryId', $attributes->categoryId);
        }

        if ($attributes->name) {
            $query = $query->where('documents.name', 'like', '%' . $attributes->name . '%')
                ->orWhere('documents.description',  'like', '%' . $attributes->name . '%');
        }

        if ($attributes->metaTags) {
            $metaTags = $attributes->metaTags;
            $query = $query->whereExists(function ($query) use ($metaTags) {
                $query->select(DB::raw(1))
                    ->from('documentMetaDatas')
                    ->whereRaw('documentMetaDatas.documentId = documents.id')
                    ->where('documentMetaDatas.metatag', 'like', '%' . $metaTags . '%');
            });
        }

        if ($attributes->createDateString) {

            $startDate = Carbon::parse($attributes->createDateString)->setTimezone('UTC');
            $endDate = Carbon::parse($attributes->createDateString)->setTimezone('UTC')->addDays(1)->addSeconds(-1);

            $query = $query->whereBetween('documents.createdDate', [$startDate, $endDate]);
        }

        $results = $query->skip($attributes->skip)->take($attributes->pageSize)->get();

        return $results;
    }

    public function getDocumentsCount($attributes)
    {
        $query = Documents::query()
            ->join('categories', 'documents.categoryId', '=', 'categories.id')
            ->join('users', 'documents.createdBy', '=', 'users.id');

        if ($attributes->categoryId) {
            $query = $query->where('categoryId', $attributes->categoryId);
        }

        if ($attributes->name) {
            $query = $query->where('documents.name', 'like', '%' . $attributes->name . '%')
                ->orWhere('documents.description',  'like', '%' . $attributes->name . '%');
        }

        if ($attributes->metaTags) {
            $metaTags = $attributes->metaTags;
            $query = $query->whereExists(function ($query) use ($metaTags) {
                $query->select(DB::raw(1))
                    ->from('documentMetaDatas')
                    ->whereRaw('documentMetaDatas.documentId = documents.id')
                    ->where('documentMetaDatas.metatag', 'like', '%' . $metaTags . '%');
            });
        }

        if ($attributes->createDateString) {
            $date = date('Y-m-d', strtotime(str_replace('/', '-', $attributes->createDateString)));

            $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
            $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();

            $query = $query->whereDate('documents.createdDate', '>=', $startDate)
                ->whereDate('documents.createdDate', '<=', $endDate);
        }

        $count = $query->count();
        return $count;
    }


    public function saveDocument($request, $path)
    {
        $model = $this->model->newInstance($request);
        $model->url = $path;
        $model->categoryId = $request->categoryId;
        $model->name = $request->name;
        $model->description = $request->description;
        $metaDatas = $request->documentMetaDatas;
        $saved = $model->save();
        $this->resetModel();
        $result = $this->parseResult($model);

        foreach (json_decode($metaDatas) as $metaTag) {
            DocumentMetaDatas::create(array(
                'documentId' =>   $result->id,
                'metatag' =>  $metaTag->metatag,
            ));
        }

        $userId = Auth::parseToken()->getPayload()->get('userId');
        DocumentUserPermissions::create(array(
            'documentId' =>   $result->id,
            'userId' =>  $userId,
            'isAllowDownload' => true
        ));

        if (!$saved) {
            throw new RepositoryException('Error in saving data.');
        }

        return (string)$result->id;
    }

    public function updateDocument($request, $id)
    {

        $model = $this->model->find($id);

        $model->name = $request->name;
        $model->description = $request->description;
        $model->categoryId = $request->categoryId;
        $metaDatas = $request->documentMetaDatas;
        $saved = $model->save();
        $this->resetModel();
        $result = $this->parseResult($model);

        $documentMetadatas = DocumentMetaDatas::where('documentId', '=', $id)->get('id');
        DocumentMetaDatas::destroy($documentMetadatas);

        foreach ($metaDatas as $metaTag) {
            DocumentMetaDatas::create(array(
                'documentId' =>  $id,
                'metatag' =>  $metaTag['metatag'],
            ));
        }

        if (!$saved) {
            throw new RepositoryException('Error in saving data.');
        }
        return $result;
    }

    public function assignedDocuments($attributes)
    {
        $userId = Auth::parseToken()->getPayload()->get('userId');
        $userRoles = UserRoles::select('roleId')
            ->where('userId', $userId)
            ->get();
        $query = Documents::select([
            'documents.id', 'documents.name', 'documents.url', 'documents.createdDate', 'documents.description', 'categories.id as categoryId', 'categories.name as categoryName',
            DB::raw("CONCAT(users.firstName,' ', users.lastName) as createdByName"),
            DB::raw("(SELECT max(documentUserPermissions.endDate) FROM documentUserPermissions
                     WHERE documentUserPermissions.documentId = documents.id and documentUserPermissions.isTimeBound =1
                     GROUP BY documentUserPermissions.documentId) as maxUserPermissionEndDate"),
            DB::raw("(SELECT max(documentRolePermissions.endDate) FROM documentRolePermissions
                     WHERE documentRolePermissions.documentId = documents.id and documentRolePermissions.isTimeBound =1
                     GROUP BY documentRolePermissions.documentId) as maxRolePermissionEndDate"),
        ])
            ->join('categories', 'documents.categoryId', '=', 'categories.id')
            ->join('users', 'documents.createdBy', '=', 'users.id')
            ->where(function ($query) use ($userId, $userRoles) {
                $query->whereExists(function ($query) use ($userId) {
                    $query->select(DB::raw(1))
                        ->from('documentUserPermissions')
                        ->whereRaw('documentUserPermissions.documentId = documents.id')
                        ->where('documentUserPermissions.userId', '=', $userId)
                        ->where(function ($query) {
                            $query->where('documentUserPermissions.isTimeBound', '=', '0')
                                ->orWhere(function ($query) {
                                    $date = date('Y-m-d');
                                    $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                                    $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();
                                    $query->where('documentUserPermissions.isTimeBound', '=', '1')
                                        ->whereDate('documentUserPermissions.startDate', '<=', $startDate)
                                        ->whereDate('documentUserPermissions.endDate', '>=', $endDate);
                                });
                        });
                })->orWhereExists(function ($query) use ($userRoles) {
                    $query->select(DB::raw(1))
                        ->from('documentRolePermissions')
                        ->whereRaw('documentRolePermissions.documentId = documents.id')
                        ->whereIn('documentRolePermissions.roleId', $userRoles)
                        ->where(function ($query) {
                            $query->where('documentRolePermissions.isTimeBound', '=', '0')
                                ->orWhere(function ($query) {
                                    $date = date('Y-m-d');
                                    $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                                    $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();
                                    $query->where('documentRolePermissions.isTimeBound', '=', '1')
                                        ->whereDate('documentRolePermissions.startDate', '<=', $startDate)
                                        ->whereDate('documentRolePermissions.endDate', '>=', $endDate);
                                });
                        });
                });
            });

        $orderByArray =  explode(' ', $attributes->orderBy);
        $orderBy = $orderByArray[0];
        $direction = $orderByArray[1] ?? 'asc';

        if ($orderBy == 'categoryName') {
            $query = $query->orderBy('categories.name', $direction);
        } else if ($orderBy == 'name') {
            $query = $query->orderBy('documents.name', $direction);
        } else if ($orderBy == 'createdDate') {
            $query = $query->orderBy('documents.createdDate', $direction);
        } else if ($orderBy == 'createdBy') {
            $query = $query->orderBy('users.firstName', $direction);
        }

        if ($attributes->categoryId) {
            $query = $query->where('categoryId', $attributes->categoryId);
        }

        if ($attributes->name) {
            $query = $query->where('documents.name', 'like', '%' . $attributes->name . '%')
                ->orWhere('documents.description',  'like', '%' . $attributes->name . '%');
        }

        if ($attributes->metaTags) {
            $metaTags = $attributes->metaTags;
            $query = $query->whereExists(function ($query) use ($metaTags) {
                $query->select(DB::raw(1))
                    ->from('documentMetaDatas')
                    ->whereRaw('documentMetaDatas.documentId = documents.id')
                    ->where('documentMetaDatas.metatag', 'like', '%' . $metaTags . '%');
            });
        }

        $results = $query->skip($attributes->skip)->take($attributes->pageSize)->get();

        return $results;
    }

    public function assignedDocumentsCount($attributes)
    {
        $userId = Auth::parseToken()->getPayload()->get('userId');
        $userRoles = UserRoles::select('roleId')
            ->where('userId', $userId)
            ->get();
        $query = Documents::query()
            ->join('categories', 'documents.categoryId', '=', 'categories.id')
            ->join('users', 'documents.createdBy', '=', 'users.id')
            ->where(function ($query) use ($userId, $userRoles) {
                $query->whereExists(function ($query) use ($userId) {
                    $query->select(DB::raw(1))
                        ->from('documentUserPermissions')
                        ->whereRaw('documentUserPermissions.documentId = documents.id')
                        ->where('documentUserPermissions.userId', '=', $userId)
                        ->where(function ($query) {
                            $query->where('documentUserPermissions.isTimeBound', '=', '0')
                                ->orWhere(function ($query) {
                                    $date = date('Y-m-d');
                                    $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                                    $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();
                                    $query->where('documentUserPermissions.isTimeBound', '=', '1')
                                        ->whereDate('documentUserPermissions.startDate', '<=', $startDate)
                                        ->whereDate('documentUserPermissions.endDate', '>=', $endDate);
                                });
                        });
                })->orWhereExists(function ($query) use ($userRoles) {
                    $query->select(DB::raw(1))
                        ->from('documentRolePermissions')
                        ->whereRaw('documentRolePermissions.documentId = documents.id')
                        ->whereIn('documentRolePermissions.roleId', $userRoles)
                        ->where(function ($query) {
                            $query->where('documentRolePermissions.isTimeBound', '=', '0')
                                ->orWhere(function ($query) {
                                    $date = date('Y-m-d');
                                    $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                                    $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();
                                    $query->where('documentRolePermissions.isTimeBound', '=', '1')
                                        ->whereDate('documentRolePermissions.startDate', '<=', $startDate)
                                        ->whereDate('documentRolePermissions.endDate', '>=', $endDate);
                                });
                        });
                });
            });

        if ($attributes->categoryId) {
            $query = $query->where('categoryId', $attributes->categoryId);
        }

        if ($attributes->name) {
            $query = $query->where('documents.name', 'like', '%' . $attributes->name . '%')
                ->orWhere('documents.description',  'like', '%' . $attributes->name . '%');
        }

        if ($attributes->metaTags) {
            $metaTags = $attributes->metaTags;
            $query = $query->whereExists(function ($query) use ($metaTags) {
                $query->select(DB::raw(1))
                    ->from('documentMetaDatas')
                    ->whereRaw('documentMetaDatas.documentId = documents.id')
                    ->where('documentMetaDatas.metatag', 'like', '%' . $metaTags . '%');
            });
        }

        $count = $query->count();
        return $count;
    }

    public function getDocumentByCategory()
    {
        $userId = Auth::parseToken()->getPayload()->get('userId');
        $userRoles = UserRoles::select('roleId')
            ->where('userId', $userId)
            ->get();

        $query = Documents::select(['documents.categoryId', 'categories.name as categoryName',  DB::raw('count(*) as documentCount')])
            ->join('categories', 'documents.categoryId', '=', 'categories.id')
            ->join('users', 'documents.createdBy', '=', 'users.id')
            ->where(function ($query) use ($userId, $userRoles) {
                $query->whereExists(function ($query) use ($userId) {
                    $query->select(DB::raw(1))
                        ->from('documentUserPermissions')
                        ->whereRaw('documentUserPermissions.documentId = documents.id')
                        ->where('documentUserPermissions.userId', '=', $userId)
                        ->where(function ($query) {
                            $query->where('documentUserPermissions.isTimeBound', '=', '0')
                                ->orWhere(function ($query) {
                                    $date = date('Y-m-d');
                                    $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                                    $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();
                                    $query->where('documentUserPermissions.isTimeBound', '=', '1')
                                        ->whereDate('documentUserPermissions.startDate', '<=', $startDate)
                                        ->whereDate('documentUserPermissions.endDate', '>=', $endDate);
                                });
                        });
                })->orWhereExists(function ($query) use ($userRoles) {
                    $query->select(DB::raw(1))
                        ->from('documentRolePermissions')
                        ->whereRaw('documentRolePermissions.documentId = documents.id')
                        ->whereIn('documentRolePermissions.roleId', $userRoles)
                        ->where(function ($query) {
                            $query->where('documentRolePermissions.isTimeBound', '=', '0')
                                ->orWhere(function ($query) {
                                    $date = date('Y-m-d');
                                    $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                                    $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();
                                    $query->where('documentRolePermissions.isTimeBound', '=', '1')
                                        ->whereDate('documentRolePermissions.startDate', '<=', $startDate)
                                        ->whereDate('documentRolePermissions.endDate', '>=', $endDate);
                                });
                        });
                });
            });

        $results =  $query->groupBy('documents.categoryId', 'categories.name')->get();

        return $results;
    }

    public function addDocumentToMe($request, $path)
    {
        $model = $this->model->newInstance($request);
        $model->url = $path;
        $model->categoryId = $request->categoryId;
        $model->name = $request->name;
        $model->description = $request->description;
        $metaDatas = $request->documentMetaDatas;
        $saved = $model->save();
        $this->resetModel();
        $result = $this->parseResult($model);

        foreach (json_decode($metaDatas) as $metaTag) {
            DocumentMetaDatas::create(array(
                'documentId' =>   $result->id,
                'metatag' =>  $metaTag->metatag,
            ));
        }

        $userId = Auth::parseToken()->getPayload()->get('userId');
        $newPermission = DocumentUserPermissions::create([
            'documentId' =>   $result->id,
            'isTimeBound' => false,
            'isAllowDownload' => true,
            'userId' => $userId,
            'createdBy' => $userId,
            'createdDate' => Carbon::now(),
        ]);
        $saved = $newPermission->save();

        $documentAudit = DocumentAuditTrails::create([
            'documentId' =>   $result->id,
            'createdBy' => $userId,
            'createdDate' => Carbon::now(),
            'operationName' => DocumentOperationEnum::Add_Permission,
            'assignToUserId' => $userId
        ]);

        $documentAudit->save();

        if (!$saved) {
            throw new RepositoryException('Error in saving data.');
        }

        $id = $result->id->toString();
        $result->id = $id;
        return  $result;
    }

    public function getDocumentbyId($id)
    {
        $userId = Auth::parseToken()->getPayload()->get('userId');
        $userRoles = UserRoles::select('roleId')
            ->where('userId', $userId)
            ->get();
        $query = Documents::select(['documents.*'])
            ->where('documents.id',  '=', $id)
            ->where(function ($query) use ($userId, $userRoles, $id) {
                $query->whereExists(function ($query) use ($userId, $id) {
                    $query->select(DB::raw(1))
                        ->from('documentUserPermissions')
                        ->where('documentUserPermissions.documentId', '=', $id)
                        ->where('documentUserPermissions.userId', '=', $userId)
                        ->where(function ($query) {
                            $query->where('documentUserPermissions.isTimeBound', '=', '0')
                                ->orWhere(function ($query) {
                                    $date = date('Y-m-d');
                                    $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                                    $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();
                                    $query->where('documentUserPermissions.isTimeBound', '=', '1')
                                        ->whereDate('documentUserPermissions.startDate', '<=', $startDate)
                                        ->whereDate('documentUserPermissions.endDate', '>=', $endDate);
                                });
                        });
                })->orWhereExists(function ($query) use ($userRoles, $id) {
                    $query->select(DB::raw(1))
                        ->from('documentRolePermissions')
                        ->where('documentRolePermissions.documentId', '=', $id)
                        ->whereIn('documentRolePermissions.roleId', $userRoles)
                        ->where(function ($query) {
                            $query->where('documentRolePermissions.isTimeBound', '=', '0')
                                ->orWhere(function ($query) {
                                    $date = date('Y-m-d');
                                    $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                                    $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();
                                    $query->where('documentRolePermissions.isTimeBound', '=', '1')
                                        ->whereDate('documentRolePermissions.startDate', '<=', $startDate)
                                        ->whereDate('documentRolePermissions.endDate', '>=', $endDate);
                                });
                        });
                });
            });

        $document = $query->first();

        if ($document == null) {
            return null;
        }

        $docUserPermissionQuery = DocumentUserPermissions::where('documentUserPermissions.documentId',  '=', $id)
            ->where('documentUserPermissions.userId', '=', $userId)
            ->where('documentUserPermissions.isAllowDownload', '=', true)
            ->where(function ($query) {
                $query->where('documentUserPermissions.isTimeBound', '=', '0')
                    ->orWhere(function ($query) {
                        $date = date('Y-m-d');
                        $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                        $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();
                        $query->where('documentUserPermissions.isTimeBound', '=', '1')
                            ->whereDate('documentUserPermissions.startDate', '<=', $startDate)
                            ->whereDate('documentUserPermissions.endDate', '>=', $endDate);
                    });
            });

        $userPermissionCount = $docUserPermissionQuery->count();
        if ($userPermissionCount > 0) {
            $document['isAllowDownload'] = true;
            return $document;
        }

        $docRolePermissionQuery = DocumentRolePermissions::where('documentRolePermissions.documentId',  '=', $id)
            ->where('documentRolePermissions.isAllowDownload', '=', true)
            ->whereIn('documentRolePermissions.roleId', $userRoles)
            ->where(function ($query) {
                $query->where('documentRolePermissions.isTimeBound', '=', '0')
                    ->orWhere(function ($query) {
                        $date = date('Y-m-d');
                        $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                        $endDate = Carbon::createFromFormat('Y-m-d', $date)->endOfDay();
                        $query->where('documentRolePermissions.isTimeBound', '=', '1')
                            ->whereDate('documentRolePermissions.startDate', '<=', $startDate)
                            ->whereDate('documentRolePermissions.endDate', '>=', $endDate);
                    });
            });

        $rolePermissionCount = $docRolePermissionQuery->count();
        if ($rolePermissionCount > 0) {
            $document['isAllowDownload'] = true;
            return $document;
        } else {
            $document['isAllowDownload'] = false;
            return $document;
        }
    }
}

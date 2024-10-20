<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupCategory;
use App\Models\User;
use App\Models\Language;
use App\Models\UserOperation;

use Illuminate\Http\Request;
use Validator;
use DB;

class GroupController extends Controller
{
    public function __construct(Request $request)
    {
        app()->setLocale($request->header('Accept-Language'));
    }

    public function get_group_attributes(Request $request)
    {
        $mentors = DB::table('users')
            ->where('users.school_id', '=', auth()->user()->school_id)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users_roles')
                    ->whereColumn('users.user_id', 'users_roles.user_id')
                    ->whereIn('users_roles.role_type_id', [3, 4]);
            })
            ->select(
                'users.user_id',
                'users.first_name',
                'users.last_name'
            )
            ->distinct()
            ->get();


        $categories = DB::table('group_categories')
            ->get();

        $attributes = new \stdClass();

        $attributes->group_mentors = $mentors;
        $attributes->group_categories = $categories;

        return response()->json($attributes, 200);
    }

    public function get_groups(Request $request)
    {
        $language = Language::where('lang_tag', '=', $request->header('Accept-Language'))->first();
        $per_page = $request->per_page ? $request->per_page : 10;

        $groups = Group::leftJoin('group_categories', 'groups.group_category_id', '=', 'group_categories.category_id')
            ->leftJoin('users as mentor', 'groups.mentor_id', '=', 'mentor.user_id')
            ->leftJoin('users as operator', 'groups.operator_id', '=', 'operator.user_id')
            ->leftJoin('schools', 'schools.school_id', '=', 'mentor.school_id')
            ->leftJoin('types_of_status', 'types_of_status.status_type_id', '=', 'groups.status_type_id')
            ->leftJoin('types_of_status_lang', 'types_of_status_lang.status_type_id', '=', 'types_of_status.status_type_id')
            ->select(
                'groups.group_id',
                'groups.group_name',
                'groups.group_description',
                'groups.created_at',
                'group_categories.category_name',
                'mentor.first_name as mentor_first_name',
                'mentor.last_name as mentor_last_name',
                'mentor.avatar as mentor_avatar',
                'operator.first_name as operator_first_name',
                'operator.last_name as operator_last_name',
                'operator.avatar as operator_avatar',
                DB::raw('(SELECT COUNT(*) FROM group_members WHERE group_members.group_id = groups.group_id) as members_count'),
                'types_of_status.color as status_color',
                'types_of_status_lang.status_type_name'
            )
            ->where('mentor.school_id', '=', auth()->user()->school_id)
            ->where('types_of_status_lang.lang_id', '=', $language->lang_id)
            ->orderBy('groups.created_at', 'desc');

            $isOnlyMentor = auth()->user()->hasOnlyRoles(['mentor']);

            if($isOnlyMentor){
                $groups->where('mentor_id', '=', auth()->user()->user_id);
            }


        $group_name = $request->group_name;
        $created_at_from = $request->created_at_from;
        $created_at_to = $request->created_at_to;

        if (!empty($group_name)) {
            $groups->where('groups.group_name', 'LIKE', '%' . $group_name . '%');
        }

        if ($created_at_from && $created_at_to) {
            $groups->whereBetween('groups.created_at', [$created_at_from . ' 00:00:00', $created_at_to . ' 23:59:00']);
        }

        if ($created_at_from) {
            $groups->where('groups.created_at', '>=', $created_at_from . ' 00:00:00');
        }

        if ($created_at_to) {
            $groups->where('groups.created_at', '<=', $created_at_to . ' 23:59:00');
        }

        return response()->json($groups->paginate($per_page)->onEachSide(1), 200);
    }

    public function get_group(Request $request)
    {
        $group = Group::leftJoin('group_categories', 'groups.group_category_id', '=', 'group_categories.category_id')
            ->select(
                'groups.group_id',
                'groups.group_name',
                'groups.group_description',
                'groups.created_at',
                'groups.group_category_id',
                'groups.mentor_id',
                'groups.operator_id',
                'group_categories.category_name'
            )
            ->where('groups.group_id', '=', $request->group_id)
            ->first();

        $members = GroupMember::where('group_id', '=', $request->group_id)
            ->leftJoin('users as member', 'group_members.member_id', '=', 'member.user_id')
            ->select(
                'member.user_id',
                'member.last_name',
                'member.first_name',
                'member.avatar'
            )
            ->get();

        $mentor = User::find($group->mentor_id);
        $operator = User::find($group->operator_id);

        $group->group_members = $members;
        $group->mentor = $mentor->only(['last_name', 'first_name', 'avatar']);
        $group->operator = $operator->only(['last_name', 'first_name', 'avatar']);

        return response()->json($group, 200);
    }

    public function create(Request $request)
    {
        $rules = [];

        if ($request->step == 1) {
            $rules = [
                'group_name' => 'required|string|between:3,300',
                'group_category_id' => 'required|numeric',
                'mentor_id' => 'required|numeric',
                'step' => 'required|numeric',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            return response()->json([
                'step' => 1
            ], 200);
        } elseif ($request->step == 2) {
            $rules = [
                'members_count' => 'required|numeric|min:1',
                'members' => 'required',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $mentor = User::find($request->mentor_id);
            $category = GroupCategory::find($request->group_category_id);

            if (!$mentor || !$category) {
                return response()->json(['error' => 'Mentor or category not found.'], 404);
            }

            return response()->json([
                'step' => 2,
                'data' => [
                    'group_name' => $request->group_name,
                    'group_description' => $request->group_description,
                    'category_name' => optional($category)->category_name,
                    'mentor' => $mentor ? $mentor->only(['last_name', 'first_name', 'avatar']) : null,
                    'members' => $request->members
                ]
            ]);
        } elseif ($request->step == 3) {
            $new_group = new Group();
            $new_group->operator_id = auth()->user()->user_id;
            $new_group->mentor_id = $request->mentor_id;
            $new_group->group_category_id = $request->group_category_id;
            $new_group->group_name = $request->group_name;
            $new_group->group_description = $request->group_description;
            $new_group->save();

            $group_members = json_decode($request->members);
            $member_names = [];

            if (count($group_members) > 0) {
                foreach ($group_members as $member) {
                    $new_member = new GroupMember();
                    $new_member->group_id = $new_group->group_id;
                    $new_member->member_id = $member->user_id;
                    $new_member->save();

                    // Сохранение имен участников
                    $member_names[] = $member->last_name . ' ' . $member->first_name;
                }
            }

            $mentor = User::find($request->mentor_id);
            $category = GroupCategory::find($request->group_category_id);

            $description = "<p><span>Название группы:</span> <b>{$new_group->group_name}</b></p>
            <p><span>Куратор:</span> <b>{$mentor->last_name} {$mentor->first_name}</b></p>
            <p><span>Категория:</span> <b>{$category->category_name}</b></p>
            <p><span>Участники:</span> <b>" . implode(", ", $member_names) . "</b></p>";

            $user_operation = new UserOperation();
            $user_operation->operator_id = auth()->user()->user_id;
            $user_operation->operation_type_id = 3;
            $user_operation->description = $description;
            $user_operation->save();

            return response()->json('success', 200);
        }
    }

    public function update(Request $request)
    {
        $rules = [];

        if ($request->step == 1) {
            $rules = [
                'group_name' => 'required|string|between:3,300',
                'group_category_id' => 'required|numeric',
                'mentor_id' => 'required|numeric',
                'step' => 'required|numeric',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            return response()->json([
                'step' => 1
            ], 200);
        } elseif ($request->step == 2) {
            $rules = [
                'members_count' => 'required|numeric|min:1',
                'members' => 'required',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $mentor = User::find($request->mentor_id);
            $category = GroupCategory::find($request->group_category_id);

            if (!$mentor || !$category) {
                return response()->json(['error' => 'Mentor or category not found.'], 404);
            }

            return response()->json([
                'step' => 2,
                'data' => [
                    'group_name' => $request->group_name,
                    'group_description' => $request->group_description,
                    'category_name' => optional($category)->category_name,
                    'mentor' => $mentor ? $mentor->only(['last_name', 'first_name', 'avatar']) : null,
                    'members' => $request->members
                ]
            ]);
        } elseif ($request->step == 3) {

            $isOwner = auth()->user()->hasRole(['super_admin', 'school_owner']);

            $edit_group = Group::find($request->group_id);
            $edit_group->operator_id = auth()->user()->user_id;
            $edit_group->mentor_id = $request->mentor_id;
            $edit_group->group_category_id = $request->group_category_id;
            $edit_group->group_name = $request->group_name;
            $edit_group->group_description = $request->group_description;
            $edit_group->status_type_id = $isOwner ? 1 : 16;
            $edit_group->save();

            // Извлекаем user_id из переданных данных
            $newMemberIds = collect(json_decode($request->members))->pluck('user_id')->toArray();

            // Получаем текущих участников группы
            $currentMembers = GroupMember::where('group_id', $request->group_id)
                ->pluck('member_id')
                ->toArray();

            // Определяем бывших участников (были в группе, но их нет в новом массиве)
            $formerMembers = array_diff($currentMembers, $newMemberIds);

            // Определяем новых участников (есть в новом массиве, но их нет в группе)
            $newMembersToAdd = array_diff($newMemberIds, $currentMembers);

            if ($isOwner) {
                // Удаляем бывших участников
                GroupMember::whereIn('member_id', $formerMembers)
                    ->where('group_id', $request->group_id)
                    ->delete();
            } else {
                // Обрабатываем бывших участников
                GroupMember::whereIn('member_id', $formerMembers)
                    ->where('group_id', $request->group_id)
                    ->update(['status_type_id' => 15]);
            }

            // Добавляем новых участников
            foreach ($newMembersToAdd as $memberId) {
                $new_member = new GroupMember();
                $new_member->group_id = $edit_group->group_id;
                $new_member->member_id = $memberId;
                $new_member->status_type_id = $isOwner ? 1 : 12;
                $new_member->save();
            }

            // Обрабатываем неизменных участников (если требуется)
            $unchangedMembers = array_intersect($currentMembers, $newMemberIds);
            // foreach ($unchangedMembers as $memberId) {
            //     GroupMember::where('member_id', $memberId)
            //         ->where('group_id', $request->group_id)
            //         ->update(['status_type_id' => 1]);
            // }

            // Извлечение новых участников
            $new_members_name = User::whereIn('users.user_id', $newMembersToAdd)
                ->get()
                ->map(function ($user) {
                    return "{$user->last_name} {$user->first_name}";
                })
                ->toArray();

            // Извлечение бывших участников
            $former_members_name = User::whereIn('users.user_id', $formerMembers)
                ->get()
                ->map(function ($user) {
                    return "{$user->last_name} {$user->first_name}";
                })
                ->toArray();

            // Извлечение неизменных участников
            $unchanged_members_name = User::whereIn('users.user_id', $unchangedMembers)
                ->get()
                ->map(function ($user) {
                    return "{$user->last_name} {$user->first_name}";
                })
                ->toArray();

            // Извлечение информации о кураторе и категории
            $mentor = User::find($request->mentor_id);
            $category = GroupCategory::find($request->group_category_id);

            // Формирование описания
            $description = "<p><span>Название группы:</span> <b>" . e($request->group_name) . "</b></p>
            <p><span>Куратор:</span> <b>" . $mentor->last_name . " " . $mentor->first_name . "</b></p>
            <p><span>Категория:</span> <b>" . $category->category_name . "</b></p>
            <p><span>Новые участники:</span> <b>" . implode(', ', $new_members_name) . "</b></p>
            <p><span>Бывшие участники:</span> <b>" . implode(', ', $former_members_name) . "</b></p>
            <p><span>Неизменные участники:</span> <b>" . implode(', ', $unchanged_members_name) . "</b></p>";


            $user_operation = new UserOperation();
            $user_operation->operator_id = auth()->user()->user_id;
            $user_operation->operation_type_id = 4;
            $user_operation->description = $description;
            $user_operation->save();

            return response()->json('success', 200);
        }
    }
}
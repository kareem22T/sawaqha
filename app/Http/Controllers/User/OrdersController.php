<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\HandleResponseTrait;
use App\Models\Order;
use App\Models\Money_request;
use App\Models\Product;
use App\Models\Ordered_Product;
use Illuminate\Support\Facades\Validator;
use App\SendEmailTrait;

class OrdersController extends Controller
{
    use HandleResponseTrait, SendEmailTrait;

    public function placeOrder(Request $request) {
        $user = $request->user();
        $cart = $user->cart()->get();

        // check if cart empty
        if (!$cart || $cart->count() === 0)
            return $this->handleResponse(
                false,
                "",
                ["العربة فارغة قم بتعبئتها اولا"],
                [],
                ["لو المستخدم مسوق وليس تاجر فعليه ان يدخل سعر بيع الطلب"]
            );

        // validate recipient info
        $validator = Validator::make($request->all(), [
            "recipient_name" => ["required"],
            "recipient_phone" => ["required"],
            "recipient_address" => ["required"],
        ], [
            "recipient_name.required" => "اسم المستلم مطلوب",
            "recipient_phone.required" => "رقم هاتف المستلم مطلوب",
            "recipient_address.required" => "عنوان المستلم مطلوب"
        ]);

        if ($validator->fails()) {
            return $this->handleResponse(
                false,
                "",
                [$validator->errors()->first()],
                [],
                ["لو المستخدم مسوق وليس تاجر فعليه ان يدخل سعر بيع الطلب"]
            );
        }

        $sub_total = 0;
        // get cart sub total
        if ($cart->count() > 0)
            foreach ($cart as $item) {
                $item_product = $item->product()->with(["gallery" => function ($q) {
                    $q->take(1);
                }])->first();
                $item->total = (int) $item->quantity >= (int) $item_product->least_quantity_wholesale ? ((int) $item_product->wholesale_price * (int) $item->quantity) : ((int) $item_product->price * (int) $item->quantity);
                $sub_total += $item->total;
            }

        // check if user is markter so ask for order sell price
        if ($user->user_type == 1) {
            $validator2 = Validator::make($request->all(), [
                "total_sell_price" => ["required", "numeric"],
            ], [
                "total_sell_price.required" => "من فضلك ادخل سعر بيع الطلب",
                "total_sell_price.numeric" => "سعر بيع الطلب يجب ان يكون رقما"
            ]);

            if ($validator2->fails()) {
                return $this->handleResponse(
                    false,
                    "",
                    [$validator2->errors()->first()],
                    [],
                    ["لو المستخدم مسوق وليس تاجر فعليه ان يدخل سعر بيع الطلب"]
                );
            }

            // check if sell price if lower than the min sell price
            if ($request->total_sell_price < $sub_total) {
                return $this->handleResponse(
                    false,
                    "",
                    ["لا يمكن بيع هذا الطلب اقل من " . $sub_total . " جنيها"],
                    [],
                    ["لو المستخدم مسوق وليس تاجر فعليه ان يدخل سعر بيع الطلب"]
                );
            }
        }


        // add user Expected profit
        // if fail so order also fail
        if ($user->user_type == 1) {
            $user->expected_profit = (float) $user->expected_profit + ((float) $request->total_sell_price - (float) $sub_total);
            $user->save();

            if (!$user) {
                return $this->handleResponse(
                    false,
                    "",
                    ["فشل اكمال الطلب"],
                    [],
                    []
                );
            }
        }

        $order = Order::create([
            "recipient_name" => $request->recipient_name,
            "recipient_phone" => $request->recipient_phone,
            "recipient_address" => $request->recipient_address,
            "sub_total" => $sub_total,
            "total_sell_price" =>  $user->user_type == 1 ? $request->total_sell_price : $sub_total,
            "user_type" => $user->user_type == 1 ? "مسوق" : "تاجر",
            "user_id" => $user->id,
            "status" => 1,
        ]);

        if (!$order) {
            $user->expected_profit = (float) $user->expected_profit - (float) $request->total_sell_price;
            $user->save();
            return $this->handleResponse(
                false,
                "",
                ["فشل اكمال الطلب"],
                [],
                []
            );
        }else {
            foreach ($cart as $item) {
                $record_product = Ordered_Product::create([
                    "order_id" => $order->id,
                    "product_id" => $item["product_id"],
                    "price_in_order" => $item["product"]["price"],
                    "ordered_quantity" => $item["quantity"],
                ]);
                $product = Product::find($item["product_id"]);
                $product->quantity = (int) $product->quantity - (int) $item["quantity"];
                $product->save();
                $item->delete();
            }

            if (!$order) {
                $user->expected_profit = (float) $user->expected_profit - (float) $request->total_sell_price;
                $user->save();
                return $this->handleResponse(
                    false,
                    "",
                    ["فشل اكمال الطلب"],
                    [],
                    []
                );
            }
        }

        if ($order) {
            $msg_content = "<h1>";
            $msg_content = " طلب جديد بواسطة" . $user->name;
            $msg_content .= "</h1>";
            $msg_content .= "<br>";
            $msg_content .= "<h3>";
            $msg_content .= "تفاصيل الطلب: ";
            $msg_content .= "</h3>";

            $msg_content .= "<h4>";
            $msg_content .= "اسم المستلم: ";
            $msg_content .= $order->recipient_name;
            $msg_content .= "</h4>";


            $msg_content .= "<h4>";
            $msg_content .= "رقم هاتف المستلم: ";
            $msg_content .= $order->recipient_phone;
            $msg_content .= "</h4>";


            $msg_content .= "<h4>";
            $msg_content .= "عنوان المستلم: ";
            $msg_content .= $order->recipient_address;
            $msg_content .= "</h4>";


            $msg_content .= "<h4>";
            $msg_content .= "الاجمالي : ";
            $msg_content .= $order->sub_total;
            $msg_content .= "</h4>";


            $msg_content .= "<h4>";
            $msg_content .= "سعر البيع : ";
            $msg_content .= $order->total_sell_price;
            $msg_content .= "</h4>";


            $msg_content .= "<h4>";
            $msg_content .= "نوع حساب الطالب : ";
            $msg_content .= $order->user_type;
            $msg_content .= "</h4>";

            $this->sendEmail("kotbekareem74@gmail.com", "طلب جديد", $msg_content);

            return $this->handleResponse(
                true,
                "تم اكتمال الطلب بنجاح سوف نتواصل مع المستلم لتاكيد وارسال الطلب",
                [],
                [
                    $order
                ],
                []
            );
        }
    }

    public function ordersAll(Request $request) {
        $user = $request->user();
        $status = $request->status;
        $order = $user->orders()->latest()->with(["products" => function ($q) {
            $q->with(["product" => function ($q) {
                $q->with("category");
            }]);
        }, "user"])->when($request->status || $request->status == 0, function ($q) use ($status) {
            $q->where("status",  $status);
        })->get();

        return $this->handleResponse(
            true,
            "عملية ناجحة",
            [],
            [$order],
            [
                "parameters" => [
                    "note" => "ال status مش مفروضة",
                    "status" => [
                        1 => "تحت المراجعة",
                        2 => "تم التاكيد",
                        3 => "بداء الشحن",
                        4 => "اكتمل",
                        5 => "فشل او الغى",
                    ]
                ]
            ]
        );
    }

    public function ordersPagination(Request $request) {
        $per_page = $request->per_page ? $request->per_page : 10;

        $user = $request->user();
        $status = $request->status;
        $order = $user->orders()->latest()->with(["products" => function ($q) {
            $q->with(["product" => function ($q) {
                $q->with("category");
            }]);
        }, "user"])->when($request->status || $request->status == 0, function ($q) use ($status) {
            $q->where("status",  $status);
        })->paginate($per_page);

        return $this->handleResponse(
            true,
            "عملية ناجحة",
            [],
            [$order],
            [
                "parameters" => [
                    "note" => "ال status مش مفروضة",
                    "status" => [
                        1 => "تحت المراجعة",
                        2 => "تم التاكيد",
                        3 => "بداء الشحن",
                        4 => "اكتمل",
                        5 => "فشل او الغى",
                    ]
                ]
            ]
        );
    }

    public function searchOrdersAll(Request $request) {
        $search = $request->search ? $request->search : '';

        $user = $request->user();
        $status = $request->status;
        $order = $user->orders()->latest()->with(["products" => function ($q) {
            $q->with(["product" => function ($q) {
                $q->with("category");
            }]);
        }, "user"])->when($request->status || $request->status == 0, function ($q) use ($status) {
            $q->where("status",  $status);
        })
        ->where(function ($query) use ($search) {
            $query->where('recipient_name', 'like', '%' . $search . '%')
                  ->orWhere('recipient_phone', 'like', '%' . $search . '%')
                  ->orWhere('recipient_address', 'like', '%' . $search . '%');
        })
        ->get();

        return $this->handleResponse(
            true,
            "عملية ناجحة",
            [],
            [$order],
            [
                "parameters" => [
                    "note" => "ال status مش مفروضة",
                    "status" => [
                        1 => "تحت المراجعة",
                        2 => "تم التاكيد",
                        3 => "بداء الشحن",
                        4 => "اكتمل",
                        5 => "فشل او الغى",
                    ]
                ]
            ]
        );
    }

    public function searchOrdersPagination(Request $request) {
        $search = $request->search ? $request->search : '';

        $per_page = $request->per_page ? $request->per_page : 10;

        $user = $request->user();
        $status = $request->status;
        $order = $user->orders()->latest()->with(["products" => function ($q) {
            $q->with(["product" => function ($q) {
                $q->with("category");
            }]);
        }, "user"])->when($request->status || $request->status == 0, function ($q) use ($status) {
            $q->where("status",  $status);
        })
        ->where(function ($query) use ($search) {
            $query->where('recipient_name', 'like', '%' . $search . '%')
                  ->orWhere('recipient_phone', 'like', '%' . $search . '%')
                  ->orWhere('recipient_address', 'like', '%' . $search . '%');
        })
        ->paginate($per_page);

        return $this->handleResponse(
            true,
            "عملية ناجحة",
            [],
            [$order],
            [
                "parameters" => [
                    "note" => "ال status مش مفروضة",
                    "status" => [
                        1 => "تحت المراجعة",
                        2 => "تم التاكيد",
                        3 => "بداء الشحن",
                        4 => "اكتمل",
                        5 => "فشل او الغى",
                    ]
                ]
            ]
        );
    }

    public function order($id) {
        $order = Order::with(["products" => function ($q) {
            $q->with(["product" => function ($q) {
                $q->with("category");
            }]);
        }, "user"])->find($id);
        if ($order)
            return $this->handleResponse(
                true,
                "عملية ناجحة",
                [],
                [$order],
                []
            );

        return $this->handleResponse(
            false,
            "",
            ["Invalid Order id"],
            [],
            []
        );
    }

    public function requestMoney(Request $request) {
        $validator = Validator::make($request->all(), [
            "amount" => ["required", "numeric"],
            "way_of_getting_money" => ["required"],
            "wallet_or_card_number" => ["required"],
        ], [
            "amount.required" => "من فضلك ادخل المبلغ المراد سحبه",
            "amount.numeric" => "يجب ان يكون المبلغ رقم",
            "way_of_getting_money.required" => "الرجاء ادخال الطريقة المراد استلام المبلغ من خلالها",
            "wallet_or_card_number.required" => "ارخل رقم المحفظة او رقم الحساب البنكي",
        ]);

        if ($validator->fails()) {
            return $this->handleResponse(
                false,
                "",
                [$validator->errors()->first()],
                [],
                []
            );
        }

        $user = $request->user();
        if ($user) {
            if ($user->withdrawRequests()->where("status", 1)->get()->count() > 0)
                return $this->handleResponse(
                    false,
                    "",
                    ["لا يمكنك اجراء طلب سحب حتى ينتهي طلبك الماضي من المراجعة"],
                    [],
                    []
                );

            if ((int) $user->balance < (int) $request->amount) {
                return $this->handleResponse(
                    false,
                    "",
                    ["لا يوجد رصيد كافي لسحب هذا المبلغ"],
                    [],
                    []
                );
            }

            $create_request = Money_request::create([
                "user_id" => $user->id,
                "amount" => $request->amount,
                "way_of_getting_money" => $request->way_of_getting_money,
                "wallet_or_card_number" => $request->wallet_or_card_number
            ]);

            if ($create_request) {
                $msg_content = "<h1>طبل سحب من المسوق: ";
                $msg_content .= $user->name;
                $msg_content .= "</h1>";
                $msg_content .= "<h2>تفاصيل الطلب: </h2>";
                $msg_content .= "<h3>الطريقة المراد استلام التحويل من خلالها: </h3>";
                $msg_content .= $create_request->way_of_getting_money;
                $msg_content .= "<h3>رقم الحساب او المحفظة: </h3>";
                $msg_content .= $create_request->way_of_getting_money;
                $msg_content .= "<h3>المبلغ المراد سحبه: </h3>";
                $msg_content .= $create_request->amount;

                $this->sendEmail("kotbekareem74@gmail.com", "طلب سحب", $msg_content);

                return $this->handleResponse(
                    true,
                    "تم تقديم الطلب بنجاح سوف تتم مراجعته في اقرب وقت!",
                    [],
                    [],
                    []
                );
            }
        } else {
        }

    }

    public function getRequests(Request $request) {
        $user = $request->user();
        $requests = $user->withdrawRequests()->get();

        return $this->handleResponse(
            true,
            "عملية ناجحة",
            [],
            [$requests],
            [
                "parameters" => [
                    "status" => [
                        1 => "تحت المراجعة",
                        2 => "تم التاكيد",
                        0 => "فشل او الغى",
                    ]
                ]
            ]
        );

    }

}

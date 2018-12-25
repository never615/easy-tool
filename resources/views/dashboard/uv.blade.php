<div id="user_uv">

    <form id="uv_new_form" class="form-inline">
        <div class="form-group margin-r-5">
            <label>维度:</label>
            <select id="uv_date_type" name="new_date_type" class="form-control">
                <option value="day">天</option>
                <option value="month">月</option>
                <option value="year">年</option>
            </select>
        </div>

        <div class="form-group margin-r-5">
            <label>区间:</label>
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                <input id="uv_new_started_at" type="text" name="new_started_at" class="form-control started_at" autocomplete="off">
            </div>
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                <input id="uv_new_ended_at" type="text" name="new_ended_at" class="form-control ended_at" autocomplete="off">
            </div>
        </div>
        <button id="uv_new_submit" type="submit" class="btn btn-primary">提交</button>
    </form>
    <hr>

    <div id="chart" style="height:400px;"></div>
</div>


<script>

    $(document).ready(function () {
        uv_DataInit();
    });


    function uv_DataInit() {
        //Chart 对象
        let chart = null;
        // 指定图表的配置项和数据
        let chartOption = null;


        dateChoiceInit('day');
        chartInit();
        const newForm = new FormData($("#user_uv #uv_new_form")[0]);
        requestNewData(newForm);


        //维度选择事件
        $("#user_uv #uv_date_type").on("change", function () {
            // console.log($(this).val());

            updateDateChoice($(this).val());

            //重新请求数据
            const newForm = new FormData($("#user_uv #uv_new_form")[0]);
            requestNewData(newForm);
        });


        //提交按钮点击事件
        $("#user_uv #uv_new_submit").on('click', function () {
            const newForm = new FormData($("#user_uv #uv_new_form")[0]);
            requestNewData(newForm);

            return false;
        });


        /**
         * 请求数据
         * @param form
         */
        function requestNewData(form) {
            //用户uv数据
            let userUvData = null;

            if ($('#subject_uuid').val() !== undefined) {
                form.append("subject_uuid", $('#subject_uuid').val());
            }


            let userUvDataRequest = doAjaxForForm("/admin/statistics/users/user_uv", "POST",
                form, function (data) {
                    userUvData = data;
                });

            $.when(userUvDataRequest)
                .done(function () {
                    let renderData = Array.from(userUvData
                        .reduce(
                            (m, x) =>
                                m.set(x.time, Object.assign(m.get(x.time) || {}, x)),
                            new Map()
                        ).values());

                    console.log(renderData);

                    if (renderData && renderData.length > 0) {
                        chartOption.dataset = {
                            source: renderData
                        };
                        chart.setOption(chartOption);
                    }else{
                        chart.clear();
                    }
                })
                .fail(function () {
                    // alert("加载数据失败,请刷新重试");
                });
        }


        //根据纬度的变化,更新日期控件
        function updateDateChoice(type) {
            let startDate;
            let endDate;
            //日历控件默认配置
            let dateOptions = {
                // format: 'YYYY-MM-DD',
                locale: '{{config("app.locale")}}',
                // minDate: '2015-12-01',
                maxDate: moment().format('YYYY-MM-DD')
            };

            const defaultStarted = moment().add(-31, 'days').format('YYYY-MM-DD');
            const defaultEnded = moment().format('YYYY-MM-DD');

            switch (type) {
                case 'day':
                    startDate = moment().add(-31, 'days').format('YYYY-MM-DD');
                    endDate = moment().format('YYYY-MM-DD');
                    dateOptions.format = 'YYYY-MM-DD';
                    dateOptions.minDate = '2018-10-01';
                    break;
                case 'month':
                    startDate = moment().add(-12, 'months').format('YYYY-MM');
                    endDate = moment().format('YYYY-MM');
                    dateOptions.format = 'YYYY-MM';
                    dateOptions.minDate = '2018-10';
                    break;
                case 'year':
                    // startDate = moment().add(-10, 'years').format('YYYY');
                    startDate = '2015';

                    endDate = moment().format('YYYY');
                    dateOptions.format = 'YYYY';
                    dateOptions.minDate = '2018';

                    break;
            }

            if ($('#user_uv #uv_new_started_at').data("DateTimePicker")) {
                $('#user_uv #uv_new_started_at').data("DateTimePicker").destroy();
            }
            if ($('#user_uv #uv_new_ended_at').data("DateTimePicker")) {
                $('#user_uv #uv_new_ended_at').data("DateTimePicker").destroy();
            }


            $('#user_uv #uv_new_started_at').datetimepicker(Object.assign(dateOptions, {
                defaultDate: defaultStarted,
                date: startDate
            }));


            $('#user_uv #uv_new_ended_at').datetimepicker(Object.assign(dateOptions, {
                defaultDate: defaultEnded,
                date: endDate
            }));
        }

        //时间选择控件初始化
        function dateChoiceInit(type) {
            //设置默认的时间维度选择
            //month
            $("#user_uv #uv_date_type option[value='" + type + "']").prop("selected", 'selected');
            updateDateChoice(type);
        }


        /**
         * 表格初始化
         */
        function chartInit() {

            chartOption = {
                title: {
                    // text: '累计用户'
                },
                toolbox: {
                    show: true,
                    feature: {
                        magicType: {type: ['line', 'bar']},
                        restore: {},
                        saveAsImage: {}
                    }
                },
                tooltip: {
                    trigger: 'axis',
                    axisPointer: {
                        type: 'cross'
                    },
                },
                legend: {},
                xAxis: {
                    type: 'category',
                },
                yAxis: {
                    min: "dataMin"
                },
                series: [{
                    name: '微信用户uv',
                    type: 'line',
                    markPoint: {
                        data: [
                            {type: 'max', name: '最大值'},
                            {type: 'min', name: '最小值'}
                        ]
                    },
                }]
            };

            chart = echarts.init($("#user_uv #chart")[0], 'walden');
            window.addEventListener('resize', () => chart.resize(), false);
        }
    }


</script>

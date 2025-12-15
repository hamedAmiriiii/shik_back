# راهنمای کامل جستجو و فیلتر در تمام لیست‌های پروژه

این فایل شامل نمونه‌های کامل برای پیاده‌سازی جستجو و فیلتر در تمام لیست‌های گرید پروژه است.

## نحوه کلی ارسال درخواست

### روش 1: جستجو بر اساس فیلدهای خاص (Object)

```javascript
const searchFilter = {
  field1: "value1",
  field2: "value2"
};

axios.get('/api/endpoint', {
  params: {
    searchFilterModel: JSON.stringify(searchFilter)
  }
});
```

### روش 2: جستجو ساده (String)

```javascript
const searchText = "متن جستجو";

axios.get('/api/endpoint', {
  params: {
    searchFilterModel: JSON.stringify(searchText)
  }
});
```

---

## لیست‌های فروشگاه

### 1. ProductController (محصولات)
**Route:** `GET /api/product`

**فیلدهای قابل جستجو:**
- `name`: نام محصول
- `barcode`: بارکد محصول

**مثال:**
```javascript
// جستجو بر اساس نام
GET /api/product?searchFilterModel={"name":"محصول"}

// جستجو بر اساس بارکد
GET /api/product?searchFilterModel={"barcode":"123"}

// جستجو در هر دو
GET /api/product?searchFilterModel={"name":"محصول","barcode":"123"}

// جستجو ساده
GET /api/product?searchFilterModel="محصول"
```

**کد React:**
```typescript
const searchProducts = async (searchFilter: { name?: string; barcode?: string } | string) => {
  const response = await axios.get('/api/product', {
    params: {
      searchFilterModel: JSON.stringify(searchFilter)
    }
  });
  return response.data;
};
```

---

### 2. PurchasedProductController (خریدها)
**Route:** `GET /api/purchased-products`

**فیلدهای قابل جستجو:**
- `phone`: شماره تلفن مشتری

**فیلترهای تاریخ:**
- `filter=today`: امروز
- `filter=week`: هفته جاری
- `filter=month`: ماه جاری
- `filter=range`: بازه تاریخ (نیاز به `from_date` و `to_date`)

**مثال:**
```javascript
// جستجو بر اساس شماره تلفن
GET /api/purchased-products?searchFilterModel={"phone":"0912"}

// فیلتر امروز
GET /api/purchased-products?filter=today

// فیلتر بازه تاریخ
GET /api/purchased-products?filter=range&from_date={"year":1403,"month":1,"day":1}&to_date={"year":1403,"month":12,"day":29}
```

**کد React:**
```typescript
const searchPurchases = async (phone?: string, filter?: string, fromDate?: any, toDate?: any) => {
  const params: any = {};
  
  if (phone) {
    params.searchFilterModel = JSON.stringify({ phone });
  }
  
  if (filter) {
    params.filter = filter;
    if (filter === 'range') {
      if (fromDate) params.from_date = JSON.stringify(fromDate);
      if (toDate) params.to_date = JSON.stringify(toDate);
    }
  }
  
  const response = await axios.get('/api/purchased-products', { params });
  return response.data;
};
```

---

### 3. ExpenseController (هزینه‌ها)
**Route:** `GET /api/expenses`

**فیلدهای قابل جستجو:**
- `title`: عنوان هزینه
- `type`: نوع هزینه (`جاری` یا `سرمایه`)
- `user_name`: نام کاربر

**فیلترهای تاریخ:**
- `filter=today`: امروز
- `filter=week`: هفته جاری
- `filter=month`: ماه جاری
- `filter=year`: سال جاری
- `filter=range`: بازه تاریخ

**مثال:**
```javascript
// جستجو بر اساس عنوان
GET /api/expenses?searchFilterModel={"title":"هزینه"}

// جستجو بر اساس نوع
GET /api/expenses?searchFilterModel={"type":"سرمایه"}

// جستجو بر اساس نام کاربر
GET /api/expenses?searchFilterModel={"user_name":"علی"}

// فیلتر ماهانه
GET /api/expenses?filter=month

// ترکیب جستجو و فیلتر
GET /api/expenses?searchFilterModel={"type":"جاری"}&filter=month
```

**کد React:**
```typescript
interface ExpenseSearch {
  title?: string;
  type?: 'جاری' | 'سرمایه';
  user_name?: string;
}

const searchExpenses = async (search?: ExpenseSearch | string, filter?: string) => {
  const params: any = {};
  
  if (search) {
    params.searchFilterModel = JSON.stringify(search);
  }
  
  if (filter) {
    params.filter = filter;
  }
  
  const response = await axios.get('/api/expenses', { params });
  return response.data;
};
```

---

### 4. CustomerController (خریداران)
**Route:** `GET /api/customers`

**فیلدهای قابل جستجو:**
- `phone`: شماره تلفن

**مثال:**
```javascript
// جستجو بر اساس شماره تلفن
GET /api/customers?searchFilterModel={"phone":"0912"}

// جستجو ساده
GET /api/customers?searchFilterModel="0912"
```

---

## لیست‌های مدیریتی (Admin)

### 5. Admin/AtelierController (آتلیه‌ها)
**Route:** `GET /api/admin/atelier`

**فیلدهای قابل جستجو:**
- `name`: نام
- `phone`: شماره تلفن

**مثال:**
```javascript
GET /api/admin/atelier?searchFilterModel={"name":"آتلیه","phone":"0912"}
```

---

### 6. Admin/CameramanController (فیلم‌برداران/عکاسان)
**Route:** `GET /api/admin/cameraman`

**فیلدهای قابل جستجو:**
- `name`: نام
- `phone`: شماره تلفن

**مثال:**
```javascript
GET /api/admin/cameraman?searchFilterModel={"name":"علی","phone":"0912"}
```

---

### 7. Admin/CeremonyController (مراسم‌ها)
**Route:** `GET /api/admin/ceremony`

**فیلدهای قابل جستجو:**
- `groom_full_name`: نام داماد
- `groom_phone`: شماره تلفن داماد

**مثال:**
```javascript
GET /api/admin/ceremony?searchFilterModel={"groom_full_name":"علی","groom_phone":"0912"}
```

---

### 8. Admin/TalarController (تالارها)
**Route:** `GET /api/admin/talar`

**فیلدهای قابل جستجو:**
- `name`: نام تالار
- `phone`: شماره تلفن

**فیلترهای خاص (relatedSearch):**
- `type`: نوع فیلتر (`0` = بدون مراسم در تاریخ، `1` = با مراسم در تاریخ)
- `date`: تاریخ (فرمت: `{"year":1403,"month":1,"day":1}`)

**مثال:**
```javascript
// جستجو ساده
GET /api/admin/talar?searchFilterModel={"name":"تالار"}

// فیلتر بر اساس تاریخ
GET /api/admin/talar?type=0&date={"year":1403,"month":1,"day":1}
```

---

### 9. Admin/GardenController (باغ‌ها)
**Route:** `GET /api/admin/garden`

**فیلدهای قابل جستجو:**
- `name`: نام باغ
- `phone`: شماره تلفن

**فیلترهای خاص (relatedSearch):**
- `type`: نوع فیلتر (`0` = بدون مراسم در تاریخ، `1` = با مراسم در تاریخ)
- `date`: تاریخ

**مثال:**
```javascript
GET /api/admin/garden?searchFilterModel={"name":"باغ"}&type=1&date={"year":1403,"month":1,"day":1}
```

---

### 10. Admin/LeaveController (مرخصی‌ها)
**Route:** `GET /api/admin/leave`

**فیلدهای قابل جستجو:**
- از طریق `search` scope در مدل Leave

**مثال:**
```javascript
GET /api/admin/leave?searchFilterModel={...}
```

---

### 11. Admin/LogSmsController (لاگ پیامک‌ها)
**Route:** `GET /api/admin/log-sms`

**فیلدهای قابل جستجو:**
- `number`: شماره تلفن
- `text`: متن پیام
- `receivers`: گیرندگان
- `creator_name`: نام کاربر

**مثال:**
```javascript
GET /api/admin/log-sms?searchFilterModel={"number":"0912","text":"پیام"}
```

---

## لیست‌های آتلیه (Atelier)

### 12. Atelier/TalarController (تالارها)
**Route:** `GET /api/atelier/talar`

**فیلدهای قابل جستجو:**
- از طریق `search` scope در مدل Talar

**مثال:**
```javascript
GET /api/atelier/talar?searchFilterModel={...}
```

---

### 13. Atelier/GardenController (باغ‌ها)
**Route:** `GET /api/atelier/garden`

**فیلدهای قابل جستجو:**
- از طریق `search` scope در مدل Garden

**مثال:**
```javascript
GET /api/atelier/garden?searchFilterModel={...}
```

---

### 14. Atelier/CeremonyController (مراسم‌ها)
**Route:** `GET /api/atelier/ceremony`

**فیلدهای قابل جستجو:**
- از طریق `search` scope در مدل Ceremony

**مثال:**
```javascript
GET /api/atelier/ceremony?searchFilterModel={...}
```

---

## لیست‌های فیلم‌بردار (Cameraman)

### 15. Cameraman/CeremonyController (مراسم‌ها)
**Route:** `GET /api/cameraman/ceremony`

**فیلدهای قابل جستجو:**
- `groom_full_name`: نام داماد
- `groom_phone`: شماره تلفن داماد

**مثال:**
```javascript
GET /api/cameraman/ceremony?searchFilterModel={"groom_full_name":"علی"}
```

---

### 16. Cameraman/LeaveController (مرخصی‌ها)
**Route:** `GET /api/cameraman/leave`

**فیلدهای قابل جستجو:**
- `date_from`: تاریخ شروع (فرمت: `{"year":1403,"month":1,"day":1}`)
- `date_to`: تاریخ پایان

**مثال:**
```javascript
GET /api/cameraman/leave?searchFilterModel={"date_from":{"year":1403,"month":1,"day":1}}
```

---

## لیست‌های جغرافیایی

### 17. CityController (شهرها)
**Route:** `GET /api/geo/cities`

**فیلدهای قابل جستجو:**
- `name`: نام شهر

**فیلتر:**
- `state_id`: فیلتر بر اساس استان

**مثال:**
```javascript
// جستجو
GET /api/geo/cities?searchFilterModel={"name":"تهران"}

// فیلتر بر اساس استان
GET /api/geo/cities?state_id=1

// ترکیب
GET /api/geo/cities?state_id=1&searchFilterModel={"name":"تهران"}
```

---

## نمونه کامل React/TypeScript

```typescript
import axios from 'axios';

// تابع عمومی برای جستجو
const searchList = async <T>(
  endpoint: string,
  searchFilter?: Record<string, any> | string,
  additionalParams?: Record<string, any>
) => {
  const params: any = { ...additionalParams };
  
  if (searchFilter) {
    params.searchFilterModel = JSON.stringify(searchFilter);
  }
  
  const response = await axios.get<T>(endpoint, { params });
  return response.data;
};

// استفاده
const products = await searchList('/api/product', { name: 'محصول' });
const expenses = await searchList('/api/expenses', { type: 'جاری' }, { filter: 'month' });
```

---

## نکات مهم

1. **searchFilterModel باید به صورت JSON string ارسال شود**
2. **اگر Object ارسال شود**: فقط در فیلدهای مشخص شده جستجو می‌کند
3. **اگر String ارسال شود**: در همه فیلدهای مرتبط جستجو می‌کند
4. **جستجو به صورت LIKE است**: یعنی بخشی از متن را پیدا می‌کند
5. **فیلترهای تاریخ**: برای برخی لیست‌ها (خریدها، هزینه‌ها) فیلتر تاریخ جداگانه وجود دارد
6. **Pagination**: همه لیست‌ها از pagination استفاده می‌کنند

---

## نمونه با Ag-Grid

```typescript
const gridOptions = {
  onFilterChanged: (params: any) => {
    const filterModel = params.api.getFilterModel();
    
    // تبدیل فیلترهای گرید به فرمت searchFilterModel
    const searchFilter: Record<string, any> = {};
    
    Object.keys(filterModel).forEach(key => {
      if (filterModel[key].filter) {
        searchFilter[key] = filterModel[key].filter;
      }
    });
    
    // ارسال درخواست
    fetchData(searchFilter);
  }
};
```

---

## نمونه با Material-UI DataGrid

```typescript
const handleFilterChange = (model: GridFilterModel) => {
  const searchFilter: Record<string, any> = {};
  
  model.items.forEach(item => {
    if (item.value) {
      searchFilter[item.field] = item.value;
    }
  });
  
  fetchData(searchFilter);
};
```


# نمونه پیاده‌سازی جستجو در لیست گریدها

این فایل شامل نمونه‌های جستجو برای تمام لیست‌های گرید در سیستم است.

## نحوه ارسال درخواست جستجو

### روش 1: جستجو بر اساس فیلدهای خاص (Object)

```javascript
// مثال با Axios
const searchData = {
  name: "محصول",
  barcode: "12345"
};

axios.get('/api/product', {
  params: {
    searchFilterModel: JSON.stringify(searchData)
  }
});
```

**URL نهایی:**
```
GET /api/product?searchFilterModel={"name":"محصول","barcode":"12345"}
```

### روش 2: جستجو ساده (String)

```javascript
// اگر فقط یک رشته ساده ارسال کنید، در هر دو فیلد name و barcode جستجو می‌کند
const searchText = "محصول";

axios.get('/api/product', {
  params: {
    searchFilterModel: JSON.stringify(searchText)
  }
});
```

**URL نهایی:**
```
GET /api/product?searchFilterModel="محصول"
```

## نمونه کامل React/TypeScript

```typescript
import axios from 'axios';

interface SearchFilter {
  name?: string;
  barcode?: string;
}

interface Product {
  id: number;
  name: string;
  barcode: string;
  purchase_price: number;
  sale_price: number;
  quantity: number;
}

// تابع جستجو
const searchProducts = async (searchFilter: SearchFilter | string) => {
  try {
    const response = await axios.get<{
      data: Product[];
      current_page: number;
      total: number;
    }>('/api/product', {
      params: {
        searchFilterModel: JSON.stringify(searchFilter)
      }
    });
    
    return response.data;
  } catch (error) {
    console.error('Error searching products:', error);
    throw error;
  }
};

// استفاده در کامپوننت
const ProductList = () => {
  const [products, setProducts] = useState<Product[]>([]);
  const [searchName, setSearchName] = useState('');
  const [searchBarcode, setSearchBarcode] = useState('');

  const handleSearch = async () => {
    const searchFilter: SearchFilter = {};
    
    if (searchName) {
      searchFilter.name = searchName;
    }
    
    if (searchBarcode) {
      searchFilter.barcode = searchBarcode;
    }
    
    const result = await searchProducts(searchFilter);
    setProducts(result.data);
  };

  return (
    <div>
      <input 
        type="text" 
        placeholder="جستجو بر اساس نام"
        value={searchName}
        onChange={(e) => setSearchName(e.target.value)}
      />
      <input 
        type="text" 
        placeholder="جستجو بر اساس بارکد"
        value={searchBarcode}
        onChange={(e) => setSearchBarcode(e.target.value)}
      />
      <button onClick={handleSearch}>جستجو</button>
      
      {/* نمایش لیست محصولات */}
    </div>
  );
};
```

## نمونه با Ag-Grid یا سایر گریدها

```typescript
// برای Ag-Grid
const gridOptions = {
  onFilterChanged: (params: any) => {
    const filterModel = params.api.getFilterModel();
    
    // تبدیل فیلترهای گرید به فرمت searchFilterModel
    const searchFilter: SearchFilter = {};
    
    if (filterModel.name) {
      searchFilter.name = filterModel.name.filter;
    }
    
    if (filterModel.barcode) {
      searchFilter.barcode = filterModel.barcode.filter;
    }
    
    // ارسال درخواست
    fetchProducts(searchFilter);
  }
};
```

## نمونه با Fetch API

```javascript
// جستجو بر اساس نام
const searchByName = async (name) => {
  const searchFilter = { name: name };
  
  const response = await fetch(
    `/api/product?searchFilterModel=${encodeURIComponent(JSON.stringify(searchFilter))}`
  );
  
  const data = await response.json();
  return data;
};

// جستجو بر اساس بارکد
const searchByBarcode = async (barcode) => {
  const searchFilter = { barcode: barcode };
  
  const response = await fetch(
    `/api/product?searchFilterModel=${encodeURIComponent(JSON.stringify(searchFilter))}`
  );
  
  const data = await response.json();
  return data;
};

// جستجو در هر دو فیلد
const searchAll = async (searchText) => {
  const response = await fetch(
    `/api/product?searchFilterModel=${encodeURIComponent(JSON.stringify(searchText))}`
  );
  
  const data = await response.json();
  return data;
};
```

## نکات مهم

1. **searchFilterModel باید به صورت JSON string ارسال شود**
2. **اگر Object ارسال شود**: فقط در فیلدهای مشخص شده جستجو می‌کند
3. **اگر String ارسال شود**: در هر دو فیلد `name` و `barcode` جستجو می‌کند
4. **جستجو به صورت LIKE است**: یعنی بخشی از متن را پیدا می‌کند (مثلاً "محص" در "محصول" پیدا می‌شود)

## مثال‌های URL برای هر کنترلر

### 1. ProductController (محصولات)
```
# جستجو بر اساس نام
/api/product?searchFilterModel={"name":"محصول"}

# جستجو بر اساس بارکد
/api/product?searchFilterModel={"barcode":"123"}

# جستجو در هر دو
/api/product?searchFilterModel={"name":"محصول","barcode":"123"}

# جستجو ساده (در هر دو فیلد)
/api/product?searchFilterModel="محصول"
```

### 2. PurchasedProductController (خریدها)
```
# جستجو بر اساس شماره تلفن
/api/purchased-products?searchFilterModel={"phone":"0912"}

# جستجو ساده
/api/purchased-products?searchFilterModel="0912"
```

### 3. ExpenseController (هزینه‌ها)
```
# جستجو بر اساس عنوان
/api/expense?searchFilterModel={"title":"هزینه"}

# جستجو بر اساس نام کاربر
/api/expense?searchFilterModel={"user_name":"علی"}

# جستجو ساده (در عنوان و نام کاربر)
/api/expense?searchFilterModel="هزینه"
```

### 4. LogSmsController (لاگ پیامک‌ها)
```
# جستجو بر اساس شماره
/api/admin/log-sms?searchFilterModel={"number":"0912"}

# جستجو بر اساس متن پیام
/api/admin/log-sms?searchFilterModel={"text":"پیام"}

# جستجو بر اساس گیرندگان
/api/admin/log-sms?searchFilterModel={"receivers":"0912"}

# جستجو بر اساس نام کاربر
/api/admin/log-sms?searchFilterModel={"creator_name":"علی"}

# جستجو ساده (در همه فیلدها)
/api/admin/log-sms?searchFilterModel="0912"
```

### 5. Admin/CeremonyController (مراسم‌ها)
```
# جستجو بر اساس نام داماد
/api/admin/ceremony?searchFilterModel={"groom_full_name":"علی"}

# جستجو بر اساس شماره تلفن داماد
/api/admin/ceremony?searchFilterModel={"groom_phone":"0912"}
```

### 6. Admin/AtelierController (آتلیه‌ها)
```
# جستجو بر اساس نام
/api/admin/atelier?searchFilterModel={"name":"آتلیه"}

# جستجو بر اساس شماره تلفن
/api/admin/atelier?searchFilterModel={"phone":"0912"}

# جستجو ساده
/api/admin/atelier?searchFilterModel="آتلیه"
```

### 7. Admin/CameramanController (فیلم‌برداران/عکاسان)
```
# جستجو بر اساس نام
/api/admin/cameraman?searchFilterModel={"name":"علی"}

# جستجو بر اساس شماره تلفن
/api/admin/cameraman?searchFilterModel={"phone":"0912"}
```

